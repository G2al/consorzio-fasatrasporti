<?php

namespace App\Services;

use App\Mail\MissingDocumentsReportMail;
use App\Models\DocumentExemption;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class MissingDocumentsReportService
{
    /**
     * @return array<string, int>
     */
    public function sendManual(User $company): array
    {
        $reports = $this->reports($company);
        $sent = [];

        foreach ($reports as $key => $report) {
            if ($report['items'] === []) {
                $sent[$key] = 0;
                continue;
            }

            Mail::to($company->email)->send(new MissingDocumentsReportMail(
                $company,
                $report['label'],
                $report['items'],
            ));

            $sent[$key] = count($report['items']);
        }

        return $sent;
    }

    /**
     * @return array<string, array{label: string, items: array<int, array{owner: string, document: string, reason: string}>}>
     */
    public function reports(User $company): array
    {
        return [
            'societa' => [
                'label' => 'Societa',
                'items' => $this->missingForOwners('societa', collect([$company]), fn (User $owner): string => $owner->name),
            ],
            'dipendenti' => [
                'label' => 'Dipendenti',
                'items' => $this->missingForOwners('dipendenti', $company->employees()->orderBy('last_name')->orderBy('first_name')->get(), fn (Employee $owner): string => trim($owner->first_name.' '.$owner->last_name)),
            ],
            'veicoli' => [
                'label' => 'Veicoli',
                'items' => $this->missingForOwners('veicoli', $company->vehicles()->orderBy('plate')->get(), fn (Vehicle $owner): string => $owner->plate.' ('.$owner->capacity.' posti)'),
            ],
        ];
    }

    /**
     * @param  Collection<int, Model>  $owners
     * @return array<int, array{owner: string, document: string, reason: string}>
     */
    private function missingForOwners(string $sectionSlug, Collection $owners, callable $ownerLabel): array
    {
        $section = Section::query()
            ->where('slug', $sectionSlug)
            ->with(['documentTemplates' => fn ($query) => $query->orderBy('name')])
            ->first();

        if (! $section) {
            return [];
        }

        $items = [];

        foreach ($owners as $owner) {
            $documents = $owner->documents()
                ->whereNull('parent_uploaded_document_id')
                ->whereNull('subtemplate_id')
                ->latest('updated_at')
                ->get()
                ->groupBy('template_id');

            $approvedExemptions = $owner->documentExemptions()
                ->where('status', 'approved')
                ->whereNull('subtemplate_id')
                ->pluck('template_id')
                ->all();

            foreach ($section->documentTemplates as $template) {
                if (in_array($template->id, $approvedExemptions, true)) {
                    continue;
                }

                $document = $this->currentDocument($documents->get($template->id));
                $status = $document?->effectiveStatus() ?? 'missing';

                if (! in_array($status, ['missing', 'expired'], true)) {
                    continue;
                }

                $items[] = [
                    'owner' => $ownerLabel($owner),
                    'document' => $template->name,
                    'reason' => $status === 'expired'
                        ? 'Scaduto'.($this->expiryLabel($document) ? ' il '.$this->expiryLabel($document) : '')
                        : 'Mancante',
                ];
            }
        }

        return $items;
    }

    private function currentDocument(?Collection $documents): ?UploadedDocument
    {
        if (! $documents || $documents->isEmpty()) {
            return null;
        }

        return $documents->first(fn (UploadedDocument $document): bool => ! $document->isExpired())
            ?: $documents->first();
    }

    private function expiryLabel(?UploadedDocument $document): ?string
    {
        if (! $document) {
            return null;
        }

        $dates = collect([$document->expiry_date, $document->internal_expiry_date])
            ->filter()
            ->sort()
            ->values();

        return $dates->first()?->format('d/m/Y');
    }
}
