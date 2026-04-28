<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\DocumentApprovals\DocumentApprovalResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\DocumentExemption;
use App\Models\DocumentSubtemplate;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DocumentOverview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.pages.document-overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->record->role === 'company', 404);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Panoramica documenti';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Panoramica documenti';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editCompany')
                ->label('Torna alla societa')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('edit', ['record' => $this->record])),
            Action::make('downloadAll')
                ->label('ZIP documenti')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'all']))
                ->openUrlInNewTab(),
        ];
    }

    public function groups(): array
    {
        return [
            $this->sectionGroup('societa', 'Societa', collect([$this->record])),
            $this->sectionGroup('dipendenti', 'Dipendenti', $this->record->employees()->orderBy('last_name')->orderBy('first_name')->get()),
            $this->sectionGroup('veicoli', 'Veicoli', $this->record->vehicles()->orderBy('plate')->get()),
        ];
    }

    public function summary(): array
    {
        $rows = collect($this->groups())
            ->flatMap(fn (array $group): array => $group['owners'])
            ->flatMap(fn (array $owner): array => $owner['rows']);

        return [
            'total' => $rows->count(),
            'missing' => $rows->where('status', 'missing')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'rejected' => $rows->where('status', 'rejected')->count(),
            'exemptions' => $rows->filter(fn (array $row): bool => str_starts_with($row['status'], 'exemption_'))->count(),
        ];
    }

    private function sectionGroup(string $slug, string $title, Collection $owners): array
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
                    'rows' => $this->ownerRows($owner, $templates),
                ])
                ->values()
                ->all(),
        ];
    }

    private function ownerRows(Model $owner, Collection $templates): array
    {
        $documents = $owner->documents()
            ->with(['template', 'subtemplate'])
            ->get()
            ->keyBy(fn (UploadedDocument $document): string => $this->documentKey($document->template_id, $document->subtemplate_id));

        $exemptions = $owner->documentExemptions()
            ->with(['template', 'subtemplate'])
            ->get()
            ->keyBy(fn (DocumentExemption $exemption): string => $this->documentKey($exemption->template_id, $exemption->subtemplate_id));

        return $templates
            ->map(fn (DocumentTemplate $template): array => $this->templateRow($template, $documents, $exemptions))
            ->values()
            ->all();
    }

    private function templateRow(DocumentTemplate $template, Collection $documents, Collection $exemptions): array
    {
        $row = $this->documentRow(
            $template->name,
            $documents->get($this->documentKey($template->id)),
            $exemptions->get($this->documentKey($template->id)),
            false,
        );

        $row['children'] = $template->subtemplates
            ->map(fn (DocumentSubtemplate $subtemplate): array => $this->documentRow(
                $subtemplate->name,
                $documents->get($this->documentKey($template->id, $subtemplate->id)),
                $exemptions->get($this->documentKey($template->id, $subtemplate->id)),
                true,
            ))
            ->values()
            ->all();

        return $row;
    }

    private function documentRow(string $name, ?UploadedDocument $document, ?DocumentExemption $exemption, bool $optional): array
    {
        $status = $document?->status ?? 'missing';

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
            'review_url' => $document ? DocumentApprovalResource::getUrl('index', ['tableSearch' => (string) $document->id]) : null,
            'uploaded_at' => $document?->created_at?->format('d/m/Y H:i'),
            'expiry_date' => $document?->expiry_date?->format('d/m/Y'),
            'internal_expiry' => $document?->internal_expiry_date
                ? trim(($document->internal_expiry_name ?: 'Requisito interno').' '.$document->internal_expiry_date->format('d/m/Y'))
                : null,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approvato',
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

    private function documentKey(int $templateId, ?int $subtemplateId = null): string
    {
        return $templateId.':'.($subtemplateId ?: 'parent');
    }
}
