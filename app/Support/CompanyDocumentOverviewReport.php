<?php

namespace App\Support;

use App\Filament\Resources\DocumentApprovals\DocumentApprovalResource;
use App\Models\DocumentExemption;
use App\Models\DocumentSubtemplate;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CompanyDocumentOverviewReport
{
    public function groups(User $company, string $filter = 'all'): array
    {
        return [
            $this->sectionGroup($company, 'societa', 'Societa', collect([$company]), $filter, true),
            $this->sectionGroup($company, 'dipendenti', 'Dipendenti', $company->employees()->orderBy('last_name')->orderBy('first_name')->get(), $filter, true),
            $this->sectionGroup($company, 'veicoli', 'Veicoli', $company->vehicles()->orderBy('plate')->get(), $filter, true),
        ];
    }

    public function groupsUnfiltered(User $company): array
    {
        return [
            $this->sectionGroup($company, 'societa', 'Societa', collect([$company]), 'all', false),
            $this->sectionGroup($company, 'dipendenti', 'Dipendenti', $company->employees()->orderBy('last_name')->orderBy('first_name')->get(), 'all', false),
            $this->sectionGroup($company, 'veicoli', 'Veicoli', $company->vehicles()->orderBy('plate')->get(), 'all', false),
        ];
    }

    public function summary(User $company): array
    {
        $rows = collect($this->groupsUnfiltered($company))
            ->flatMap(fn (array $group): array => $group['owners'])
            ->flatMap(fn (array $owner): array => $this->flattenRows($owner['rows'])->all());

        return [
            'total' => $rows->count(),
            'missing' => $rows->filter(fn (array $row): bool => in_array($row['status'], ['missing', 'expired'], true))->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'rejected' => $rows->where('status', 'rejected')->count(),
            'expired' => $rows->where('status', 'expired')->count(),
            'expiring' => $rows->filter(fn (array $row): bool => $row['is_expiring'])->count(),
            'exemptions' => $rows->filter(fn (array $row): bool => str_starts_with($row['status'], 'exemption_'))->count(),
        ];
    }

    public function pdfRows(User $company, string $filter = 'all'): array
    {
        $rows = [];

        foreach ($this->groups($company, $filter) as $group) {
            foreach ($group['owners'] as $owner) {
                foreach ($this->flattenRows($owner['rows']) as $row) {
                    $dateParts = [];

                    if ($row['uploaded_at']) {
                        $dateParts[] = 'Caricato: '.$row['uploaded_at'];
                    }

                    if ($row['expiry_date']) {
                        $dateParts[] = 'Scadenza: '.$row['expiry_date'];
                    }

                    if ($row['internal_expiry']) {
                        $dateParts[] = $row['internal_expiry'];
                    }

                    $rows[] = [
                        $group['title'],
                        $owner['label'],
                        ($row['optional'] ? 'Opzionale - ' : '').$row['name'],
                        $row['is_expiring'] ? 'In scadenza' : $row['status_label'],
                        $dateParts !== [] ? implode(' | ', $dateParts) : '-',
                        $row['notes'] ?: '-',
                    ];
                }
            }
        }

        return $rows;
    }

    public function filterLabel(string $filter): string
    {
        return match ($filter) {
            'missing' => 'Mancanti',
            'pending' => 'In attesa',
            'approved' => 'Approvati',
            'rejected' => 'Respinti',
            'expired' => 'Scaduti',
            'expiring' => 'In scadenza',
            'exemptions' => 'Esenzioni',
            default => 'Totali',
        };
    }

    private function sectionGroup(User $company, string $slug, string $title, Collection $owners, string $filter, bool $filtered): array
    {
        $section = Section::query()
            ->where('slug', $slug)
            ->with(['documentTemplates' => fn ($query) => $query->with('subtemplates')->orderBy('name')])
            ->first();

        $templates = $section?->documentTemplates ?? collect();

        return [
            'title' => $title,
            'slug' => $slug,
            'owners' => $owners
                ->map(fn (Model $owner): array => [
                    'label' => $this->ownerLabel($owner),
                    'meta' => $this->ownerMeta($owner),
                    'rows' => $this->ownerRows($owner, $templates, $filter, $filtered),
                ])
                ->filter(fn (array $owner): bool => $owner['rows'] !== [] || $filter === 'all')
                ->values()
                ->all(),
        ];
    }

    private function ownerRows(Model $owner, Collection $templates, string $filter, bool $filtered): array
    {
        $documents = $owner->documents()
            ->whereNull('parent_uploaded_document_id')
            ->with(['template.section', 'subtemplate', 'integrations'])
            ->latest('updated_at')
            ->get()
            ->groupBy(fn (UploadedDocument $document): string => $this->documentKey($document->template_id, $document->subtemplate_id));

        $exemptions = $owner->documentExemptions()
            ->with(['template', 'subtemplate'])
            ->get()
            ->keyBy(fn (DocumentExemption $exemption): string => $this->documentKey($exemption->template_id, $exemption->subtemplate_id));

        return $templates
            ->map(fn (DocumentTemplate $template): array => $this->templateRow($template, $documents, $exemptions))
            ->map(fn (array $row): ?array => $filtered ? $this->filterRow($row, $filter) : $row)
            ->filter()
            ->values()
            ->all();
    }

    private function templateRow(DocumentTemplate $template, Collection $documents, Collection $exemptions): array
    {
        $document = $this->currentDocument($documents->get($this->documentKey($template->id)));
        $row = $this->documentRow(
            $template->name,
            $document,
            $exemptions->get($this->documentKey($template->id)),
            false,
        );

        $subtemplateRows = $template->subtemplates
            ->map(fn (DocumentSubtemplate $subtemplate): array => $this->documentRow(
                $subtemplate->name,
                $this->currentDocument($documents->get($this->documentKey($template->id, $subtemplate->id))),
                $exemptions->get($this->documentKey($template->id, $subtemplate->id)),
                true,
            ))
            ->values()
            ->all();

        $integrationRows = $document?->integrations
            ->map(fn (UploadedDocument $integration): array => $this->documentRow(
                $integration->integration_name ?: 'Integrazione',
                $integration,
                null,
                true,
            ))
            ->values()
            ->all() ?? [];

        $row['children'] = [...$subtemplateRows, ...$integrationRows];

        return $row;
    }

    private function documentRow(string $name, ?UploadedDocument $document, ?DocumentExemption $exemption, bool $optional): array
    {
        $status = $document?->effectiveStatus() ?? 'missing';

        if ($exemption?->status) {
            $status = 'exemption_'.$exemption->status;
        }

        return [
            'name' => $name,
            'optional' => $optional,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'document' => $document,
            'exemption' => $exemption,
            'notes' => $document?->admin_notes ?: $exemption?->admin_notes,
            'download_url' => $document ? route('admin.downloads.documents.show', $document) : null,
            'review_url' => $document ? $this->reviewUrl($document) : null,
            'uploaded_at' => $document?->created_at?->format('d/m/Y H:i'),
            'expiry_date' => $document?->expiry_date?->format('d/m/Y'),
            'internal_expiry' => $document?->internal_expiry_date
                ? trim(($document->internal_expiry_name ?: 'Requisito interno').' '.$document->internal_expiry_date->format('d/m/Y'))
                : null,
            'is_expiring' => $document ? $this->isExpiring($document) : false,
            'children' => [],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approvato',
            'expired' => 'Scaduto',
            'pending' => 'In attesa',
            'rejected' => 'Respinto',
            'exemption_approved' => 'Esente',
            'exemption_pending' => 'Esenzione in attesa',
            'exemption_rejected' => 'Esenzione rifiutata',
            default => 'Mancante',
        };
    }

    private function ownerLabel(Model $owner): string
    {
        return match (true) {
            $owner instanceof User => $owner->name,
            $owner instanceof Employee => trim($owner->first_name.' '.$owner->last_name),
            $owner instanceof Vehicle => $owner->plate,
            default => 'Elemento',
        };
    }

    private function ownerMeta(Model $owner): ?string
    {
        return match (true) {
            $owner instanceof User => $owner->email,
            $owner instanceof Employee => $owner->phone ?: null,
            $owner instanceof Vehicle => $owner->capacity.' posti',
            default => null,
        };
    }

    private function reviewUrl(UploadedDocument $document): string
    {
        return DocumentApprovalResource::getUrl('index', [
            'activeTab' => $document->template->section?->slug ?: 'societa',
            'tableAction' => 'review',
            'tableActionRecord' => (string) $document->getKey(),
        ]);
    }

    private function documentKey(int $templateId, ?int $subtemplateId = null): string
    {
        return $templateId.':'.($subtemplateId ?: 'parent');
    }

    private function filterRow(array $row, string $filter): ?array
    {
        if ($filter === 'all') {
            return $row;
        }

        $children = collect($row['children'] ?? [])
            ->filter(fn (array $child): bool => $this->rowMatchesFilter($child, $filter))
            ->values()
            ->all();

        $matches = $this->rowMatchesFilter($row, $filter);

        if (! $matches && $children === []) {
            return null;
        }

        $row['children'] = $matches ? ($row['children'] ?? []) : $children;

        return $row;
    }

    private function rowMatchesFilter(array $row, string $filter): bool
    {
        return match ($filter) {
            'missing' => in_array($row['status'], ['missing', 'expired'], true),
            'pending' => $row['status'] === 'pending',
            'approved' => $row['status'] === 'approved',
            'rejected' => $row['status'] === 'rejected',
            'expired' => $row['status'] === 'expired',
            'expiring' => (bool) $row['is_expiring'],
            'exemptions' => str_starts_with($row['status'], 'exemption_'),
            default => true,
        };
    }

    private function flattenRows(array $rows): Collection
    {
        return collect($rows)
            ->flatMap(fn (array $row): array => [$row, ...($row['children'] ?? [])]);
    }

    private function isExpiring(UploadedDocument $document): bool
    {
        if ($document->status !== 'approved' || $document->isExpired()) {
            return false;
        }

        $thresholds = collect(explode(',', (string) config('services.documents.deadline_reminder_days', '30,15')))
            ->map(fn (string $day): int => (int) trim($day))
            ->filter(fn (int $day): bool => $day > 0)
            ->values();
        $maxDays = $thresholds->max() ?: 30;
        $today = now()->startOfDay();

        foreach ([$document->expiry_date, $document->internal_expiry_date] as $date) {
            if (! $date) {
                continue;
            }

            $days = (int) $today->diffInDays($date->copy()->startOfDay(), false);

            if ($days >= 0 && $days <= $maxDays) {
                return true;
            }
        }

        return false;
    }

    private function currentDocument(?Collection $documents): ?UploadedDocument
    {
        if (! $documents || $documents->isEmpty()) {
            return null;
        }

        return $documents->first(fn (UploadedDocument $document): bool => ! $document->isExpired())
            ?: $documents->first();
    }
}
