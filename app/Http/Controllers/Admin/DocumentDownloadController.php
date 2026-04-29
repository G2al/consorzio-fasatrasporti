<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\SimplePdf;
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

        return $this->pdfResponse(
            'Riepilogo documenti - '.$company->name,
            ['Societa', 'Documento', 'Sezione', 'Scadenza'],
            $this->companyDocumentReportRows($documents),
            'riepilogo-documenti-'.$scopeLabel.'-'.$this->slug($company->name).'.pdf',
        );
    }

    public function template(DocumentTemplate $template): BinaryFileResponse
    {
        $documents = UploadedDocument::query()
            ->where('template_id', $template->id)
            ->where('status', 'approved')
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->latest('approved_at')
            ->get();

        return $this->zipResponse(
            $documents,
            'documenti-approvati-'.$this->slug($template->name).'.zip',
            fn (UploadedDocument $document): string => $this->templateZipPath($document),
            'Nessun documento approvato disponibile per il download.',
        );
    }

    public function templatePdf(DocumentTemplate $template): Response
    {
        $documents = UploadedDocument::query()
            ->where('template_id', $template->id)
            ->where('status', 'approved')
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->latest('approved_at')
            ->get();

        return $this->pdfResponse(
            'Riepilogo approvati - '.$template->name,
            ['Societa', 'Documento', 'Scadenza'],
            $this->templateDocumentReportRows($documents),
            'riepilogo-approvati-'.$this->slug($template->name).'.pdf',
        );
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

            if ($document->internal_expiry_date) {
                $expiry .= ' | '.($document->internal_expiry_name ?: 'Requisito interno').': '.$document->internal_expiry_date->format('d/m/Y');
            }

            $rows[] = [
                $company,
                $this->documentName($document),
                $expiry,
            ];
        }

        return $rows;
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
