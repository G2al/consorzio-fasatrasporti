<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CompanyDocumentOverviewReport;
use App\Support\SimplePdf;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class DocumentDownloadController extends Controller
{
    public function document(UploadedDocument $document): BinaryFileResponse
    {
        abort_unless(Storage::disk('public')->exists($document->file_path), Response::HTTP_NOT_FOUND);

        return response()->download(
            Storage::disk('public')->path($document->file_path),
            $this->documentFileName($document),
        );
    }

    public function company(User $company, string $scope = 'all'): BinaryFileResponse
    {
        abort_unless($company->role === 'company', Response::HTTP_NOT_FOUND);
        abort_unless(in_array($scope, ['all', 'company', 'employees', 'vehicles'], true), Response::HTTP_NOT_FOUND);

        $documents = $this->companyDocumentsQuery($company, $scope)
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->orderBy('template_id')
            ->orderBy('subtemplate_id')
            ->get();

        $scopeLabel = match ($scope) {
            'company' => 'societa',
            'employees' => 'dipendenti',
            'vehicles' => 'veicoli',
            default => 'tutti',
        };

        return $this->zipResponse(
            $documents,
            'documenti-'.$scopeLabel.'-'.$this->slug($company->name).'.zip',
            fn (UploadedDocument $document): string => $this->companyZipPath($document, $scope),
            'Nessun documento disponibile per il download.',
        );
    }

    public function companyPdf(User $company, string $scope = 'all'): Response
    {
        abort_unless($company->role === 'company', Response::HTTP_NOT_FOUND);
        abort_unless(in_array($scope, ['all', 'company', 'employees', 'vehicles'], true), Response::HTTP_NOT_FOUND);
        $filter = request()->query('filter', 'all');
        $report = app(CompanyDocumentOverviewReport::class);
        $filterLabel = $report->filterLabel($filter);

        $scopeLabel = match ($scope) {
            'company' => 'societa',
            'employees' => 'dipendenti',
            'vehicles' => 'veicoli',
            default => 'tutti',
        };
        $title = $filter === 'all'
            ? 'Riepilogo documenti - '.$company->name
            : 'Riepilogo '.$filterLabel.' - '.$company->name;
        $fileSuffix = $filter === 'all' ? $scopeLabel : $this->slug($filterLabel).'-'.$scopeLabel;

        return $this->pdfResponse(
            $title,
            ['Sezione', 'Elemento', 'Documento', 'Stato', 'Date', 'Note'],
            $report->pdfRows($company, $filter),
            'riepilogo-documenti-'.$fileSuffix.'-'.$this->slug($company->name).'.pdf',
        );
    }

    public function companyOverviewPdf(): Response
    {
        $filter = request()->query('filter', 'all');
        $report = app(CompanyDocumentOverviewReport::class);
        $filterLabel = $report->filterLabel($filter);
        $title = $filter === 'all'
            ? 'Panoramica totale societa'
            : 'Panoramica '.$filterLabel.' societa';
        $fileSuffix = $filter === 'all' ? 'societa-tutte' : $this->slug($filterLabel).'-societa-tutte';

        return $this->pdfResponse(
            $title,
            ['Societa', 'Documento', 'Stato', 'Note'],
            $report->globalCompanySectionPdfRows($filter),
            'panoramica-totale-'.$fileSuffix.'.pdf',
        );
    }

    public function template(DocumentTemplate $template): BinaryFileResponse
    {
        $documents = $this->latestApprovedTemplateDocuments($template);

        return $this->zipResponse(
            $documents,
            'documenti-approvati-'.$this->slug($template->name).'.zip',
            fn (UploadedDocument $document): string => $this->templateZipPath($document),
            'Nessun documento approvato disponibile per il download.',
        );
    }

    public function templatePdf(DocumentTemplate $template): Response
    {
        $documents = $this->latestApprovedTemplateDocuments($template);

        return $this->pdfResponse(
            'Riepilogo approvati - '.$template->name,
            ['Societa', 'Documento', 'Stato', 'Scadenza'],
            $this->templateDocumentReportRows($documents),
            'riepilogo-approvati-'.$this->slug($template->name).'.pdf',
        );
    }

    public function templateMissingPdf(DocumentTemplate $template): Response
    {
        return $this->pdfResponse(
            'Riepilogo mancanti - '.$template->name,
            ['Societa', 'Responsabile', 'Email', 'Stato'],
            $this->templateMissingCompanyRows($template),
            'riepilogo-mancanti-'.$this->slug($template->name).'.pdf',
        );
    }

    /**
     * @return Collection<int, UploadedDocument>
     */
    private function latestApprovedTemplateDocuments(DocumentTemplate $template): Collection
    {
        return UploadedDocument::query()
            ->where('template_id', $template->id)
            ->where('status', 'approved')
            ->whereNull('parent_uploaded_document_id')
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->get()
            ->sortByDesc(fn (UploadedDocument $document): int => $document->approved_at?->getTimestamp() ?? $document->updated_at?->getTimestamp() ?? 0)
            ->groupBy(fn (UploadedDocument $document): string => $this->templateCompanyGroupingKey($document))
            ->map(fn (Collection $documents): UploadedDocument => $documents->first())
            ->values();
    }

    private function companyDocumentsQuery(User $company, string $scope): Builder
    {
        return UploadedDocument::query()
            ->where(function (Builder $query) use ($company, $scope): void {
                if (in_array($scope, ['all', 'company'], true)) {
                    $query->orWhere(function (Builder $query) use ($company): void {
                        $query
                            ->where('documentable_type', User::class)
                            ->where('documentable_id', $company->id);
                    });
                }

                if (in_array($scope, ['all', 'employees'], true)) {
                    $query->orWhere(function (Builder $query) use ($company): void {
                        $query
                            ->where('documentable_type', Employee::class)
                            ->whereIn('documentable_id', Employee::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    });
                }

                if (in_array($scope, ['all', 'vehicles'], true)) {
                    $query->orWhere(function (Builder $query) use ($company): void {
                        $query
                            ->where('documentable_type', Vehicle::class)
                            ->whereIn('documentable_id', Vehicle::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    });
                }
            });
    }

    /**
     * @param  iterable<UploadedDocument>  $documents
     */
    private function zipResponse(iterable $documents, string $downloadName, callable $pathResolver, string $emptyMessage): BinaryFileResponse
    {
        $exportDirectory = storage_path('app/private/exports');

        if (! is_dir($exportDirectory)) {
            mkdir($exportDirectory, 0755, true);
        }

        $zipPath = $exportDirectory.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive();

        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, Response::HTTP_INTERNAL_SERVER_ERROR);

        $added = [];

        foreach ($documents as $document) {
            if (! Storage::disk('public')->exists($document->file_path)) {
                continue;
            }

            $entryPath = $this->uniqueZipPath($pathResolver($document), $added);
            $zip->addFile(Storage::disk('public')->path($document->file_path), $entryPath);
        }

        $zip->close();

        if ($added === []) {
            @unlink($zipPath);
            abort(Response::HTTP_NOT_FOUND, $emptyMessage);
        }

        return response()
            ->download($zipPath, $downloadName)
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  iterable<UploadedDocument>  $documents
     * @return array<int, string>
     */
    private function companyDocumentReportRows(iterable $documents): array
    {
        $rows = [];

        foreach ($documents as $document) {
            $company = $document->companyUser()?->name ?: 'Societa non disponibile';
            $expiry = $document->expiry_date?->format('d/m/Y') ?: '-';

            if ($document->internal_expiry_date) {
                $expiry .= ' | '.($document->internal_expiry_name ?: 'Requisito interno').': '.$document->internal_expiry_date->format('d/m/Y');
            }

            $rows[] = [
                $company,
                $this->documentName($document),
                $this->documentSectionLabel($document),
                $expiry,
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<UploadedDocument>  $documents
     * @return array<int, string>
     */
    private function templateDocumentReportRows(iterable $documents): array
    {
        $rows = [];

        foreach ($documents as $document) {
            $company = $document->companyUser()?->name ?: 'Societa non disponibile';
            $expiry = $document->expiry_date?->format('d/m/Y') ?: '-';
            $status = $document->isExpired() ? 'Scaduto' : 'Approvato';

            if ($document->internal_expiry_date) {
                $expiry .= ' | '.($document->internal_expiry_name ?: 'Requisito interno').': '.$document->internal_expiry_date->format('d/m/Y');
            }

            if ($document->isExpired()) {
                $expiry = $expiry !== '-'
                    ? 'Scaduto il '.$expiry
                    : 'Scaduto';
            }

            $rows[] = [
                $company,
                $this->documentName($document),
                $status,
                $expiry,
            ];
        }

        return $rows;
    }

    private function templateCompanyGroupingKey(UploadedDocument $document): string
    {
        $company = $document->companyUser();

        return $company
            ? 'company:'.$company->getKey()
            : $document->documentable_type.':'.$document->documentable_id;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function templateMissingCompanyRows(DocumentTemplate $template): array
    {
        $companies = User::query()
            ->where('role', 'company')
            ->whereNotExists($this->templateDocumentExistsSubquery($template))
            ->whereNotExists($this->templateExemptionExistsSubquery($template))
            ->orderBy('name')
            ->get(['name', 'responsible_name', 'email']);

        return $companies
            ->map(fn (User $company): array => [
                $company->name,
                $company->responsible_name ?: '-',
                $company->email ?: '-',
                'Mancante',
            ])
            ->all();
    }

    private function templateDocumentExistsSubquery(DocumentTemplate $template): Builder
    {
        $sectionSlug = $template->section?->slug;

        return UploadedDocument::query()
            ->selectRaw('1')
            ->where('template_id', $template->id)
            ->whereNull('parent_uploaded_document_id')
            ->where(function (Builder $query) use ($sectionSlug): void {
                match ($sectionSlug) {
                    'dipendenti' => $query
                        ->where('documentable_type', Employee::class)
                        ->whereExists(Employee::query()
                            ->selectRaw('1')
                            ->whereColumn('employees.id', 'uploaded_documents.documentable_id')
                            ->whereColumn('employees.user_id', 'users.id')),
                    'veicoli' => $query
                        ->where('documentable_type', Vehicle::class)
                        ->whereExists(Vehicle::query()
                            ->selectRaw('1')
                            ->whereColumn('vehicles.id', 'uploaded_documents.documentable_id')
                            ->whereColumn('vehicles.user_id', 'users.id')),
                    default => $query
                        ->where('documentable_type', User::class)
                        ->whereColumn('documentable_id', 'users.id'),
                };
            })
            ->limit(1);
    }

    private function templateExemptionExistsSubquery(DocumentTemplate $template): Builder
    {
        $sectionSlug = $template->section?->slug;

        return \App\Models\DocumentExemption::query()
            ->selectRaw('1')
            ->where('template_id', $template->id)
            ->where(function (Builder $query) use ($sectionSlug): void {
                match ($sectionSlug) {
                    'dipendenti' => $query
                        ->where('exemptable_type', Employee::class)
                        ->whereExists(Employee::query()
                            ->selectRaw('1')
                            ->whereColumn('employees.id', 'document_exemptions.exemptable_id')
                            ->whereColumn('employees.user_id', 'users.id')),
                    'veicoli' => $query
                        ->where('exemptable_type', Vehicle::class)
                        ->whereExists(Vehicle::query()
                            ->selectRaw('1')
                            ->whereColumn('vehicles.id', 'document_exemptions.exemptable_id')
                            ->whereColumn('vehicles.user_id', 'users.id')),
                    default => $query
                        ->where('exemptable_type', User::class)
                        ->whereColumn('exemptable_id', 'users.id'),
                };
            })
            ->limit(1);
    }

    private function pdfResponse(string $title, array $columns, array $rows, string $downloadName): Response
    {
        return response(SimplePdf::table($title, $columns, $rows), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$downloadName.'"',
        ]);
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueZipPath(string $path, array &$used): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = $extension ? substr($path, 0, -strlen($extension) - 1) : $path;
        $candidate = $path;
        $index = 2;

        while (isset($used[$candidate])) {
            $candidate = $base.'-'.$index.($extension ? '.'.$extension : '');
            $index++;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    private function companyZipPath(UploadedDocument $document, string $scope): string
    {
        $section = $this->slug($document->template->section?->name ?: 'documenti');
        $owner = $this->slug($this->documentOwnerLabel($document));

        if ($scope === 'company') {
            return $this->documentFileName($document);
        }

        return $section.'/'.$owner.'/'.$this->documentFileName($document);
    }

    private function templateZipPath(UploadedDocument $document): string
    {
        $company = $this->slug($document->companyUser()?->name ?: 'societa');
        $owner = $this->slug($this->documentOwnerLabel($document));

        return $company.'/'.$owner.'/'.$this->documentFileName($document);
    }

    private function documentFileName(UploadedDocument $document): string
    {
        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $parts = array_filter([
            $document->template?->name,
            $document->subtemplate?->name,
            $this->documentOwnerLabel($document),
            $document->approved_at?->format('Ymd') ?: $document->updated_at?->format('Ymd'),
        ]);

        return $this->slug(implode('-', $parts)).($extension ? '.'.$extension : '');
    }

    private function documentName(UploadedDocument $document): string
    {
        return trim(implode(' / ', array_filter([
            $document->template?->name,
            $document->subtemplate?->name,
            $document->integration_name ? 'Integrazione: '.$document->integration_name : null,
        ])));
    }

    private function documentOwnerLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim($documentable->first_name.' '.$documentable->last_name),
            $documentable instanceof Vehicle => $documentable->plate.' '.$documentable->capacity.' posti',
            default => 'elemento',
        };
    }

    private function documentSectionLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => 'Societa',
            $documentable instanceof Employee => 'Dipendenti - '.trim($documentable->first_name.' '.$documentable->last_name),
            $documentable instanceof Vehicle => 'Veicoli - '.$documentable->plate.' ('.$documentable->capacity.' posti)',
            default => $document->template->section?->name ?: 'Documento',
        };
    }

    private function slug(string $value): string
    {
        $slug = Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-')
            ->limit(90, '')
            ->toString();

        return $slug !== '' ? $slug : 'documento';
    }
}
