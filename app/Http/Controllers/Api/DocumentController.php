<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentExemption;
use App\Models\DocumentSubtemplate;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TelegramNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(private readonly TelegramNotifier $telegramNotifier) {}

    public function companyDocuments(Request $request): JsonResponse
    {
        return response()->json($this->documentsPayload('societa', $request->user()));
    }

    public function employeeDocuments(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($employee->user_id === $request->user()->id, 404);

        return response()->json($this->documentsPayload('dipendenti', $employee));
    }

    public function vehicleDocuments(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);

        return response()->json($this->documentsPayload('veicoli', $vehicle));
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'subtemplate_id' => ['nullable', 'integer', 'exists:document_subtemplates,id'],
            'documentable_type' => ['required', 'string', 'in:company,employee,vehicle'],
            'documentable_id' => ['nullable', 'integer'],
            'has_expiry' => ['required', 'boolean'],
            'expiry_date' => ['nullable', 'required_if:has_expiry,1,true,on', 'date'],
            'internal_expiry_name' => ['nullable', 'required_with:internal_expiry_date', 'string', 'max:255'],
            'internal_expiry_date' => ['nullable', 'required_with:internal_expiry_name', 'date'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.config('services.documents.upload_max_kb')],
            'files' => ['nullable', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:'.config('services.documents.upload_max_kb')],
        ]);

        $template = DocumentTemplate::query()
            ->with('section')
            ->findOrFail($data['template_id']);
        $subtemplate = $this->resolveSubtemplate($template, $data['subtemplate_id'] ?? null);

        $this->assertTemplateMatchesType($template, $data['documentable_type']);

        $documentable = $this->resolveDocumentable(
            $request,
            $data['documentable_type'],
            $data['documentable_id'] ?? null,
        );

        abort_if($this->approvedExemptionExists($documentable, $template, $subtemplate), 422, 'Questo documento risulta esente e non puo essere caricato.');

        $hasExpiry = $subtemplate ? false : $request->boolean('has_expiry');
        $files = $this->resolveUploadFiles($request, $subtemplate);
        $previousDocument = $this->currentDocument($documentable->documents()
            ->where('template_id', $template->id)
            ->where('subtemplate_id', $subtemplate?->id)
            ->whereNull('parent_uploaded_document_id')
            ->latest('updated_at')
            ->get());

        $document = $this->storeDocument(
            $documentable,
            $template,
            $subtemplate,
            $files,
            $data['documentable_type'],
            $request->user()->id,
            $hasExpiry,
            $subtemplate ? null : ($data['expiry_date'] ?? null),
            $subtemplate ? null : ($data['internal_expiry_name'] ?? null),
            $subtemplate ? null : ($data['internal_expiry_date'] ?? null),
        );
        AuditLog::record('document.uploaded', $document, 'Documento caricato', [
            'template' => $template->name,
            'subtemplate' => $subtemplate?->name,
            'type' => $data['documentable_type'],
            'expiry_date' => $subtemplate ? null : ($data['expiry_date'] ?? null),
            'internal_expiry_name' => $subtemplate ? null : ($data['internal_expiry_name'] ?? null),
            'internal_expiry_date' => $subtemplate ? null : ($data['internal_expiry_date'] ?? null),
        ], actor: $request->user());

        $this->telegramNotifier->notifyDocumentUploaded(
            $document->fresh(['template.section', 'subtemplate', 'documentable']),
            $previousDocument?->isExpired() ? 'expired_update' : ($previousDocument?->status === 'approved' ? 'replacement' : 'uploaded'),
        );

        return response()->json([
            'document' => $this->uploadedDocumentPayload($document->fresh(['template', 'attachments'])),
        ], 201);
    }

    public function requestExemption(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'subtemplate_id' => ['nullable', 'integer', 'exists:document_subtemplates,id'],
            'documentable_type' => ['required', 'string', 'in:company,employee,vehicle'],
            'documentable_id' => ['nullable', 'integer'],
            'requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $template = DocumentTemplate::query()
            ->with('section')
            ->findOrFail($data['template_id']);
        $subtemplate = $this->resolveSubtemplate($template, $data['subtemplate_id'] ?? null);

        $this->assertTemplateMatchesType($template, $data['documentable_type']);

        $documentable = $this->resolveDocumentable(
            $request,
            $data['documentable_type'],
            $data['documentable_id'] ?? null,
        );

        $exemption = $documentable->documentExemptions()
            ->updateOrCreate(
                [
                    'template_id' => $template->id,
                    'subtemplate_id' => $subtemplate?->id,
                ],
                [
                    'status' => 'pending',
                    'requested_reason' => $data['requested_reason'] ?? null,
                    'admin_notes' => null,
                    'reviewed_at' => null,
                ],
            );

        AuditLog::record('document_exemption.requested', $exemption, 'Esenzione documento richiesta', [
            'template' => $template->name,
            'subtemplate' => $subtemplate?->name,
            'type' => $data['documentable_type'],
            'reason' => $data['requested_reason'] ?? null,
        ], actor: $request->user(), company: $request->user());

        return response()->json([
            'exemption' => $this->exemptionPayload($exemption->fresh(['template'])),
        ], 201);
    }

    public function requestIntegrations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'documentable_type' => ['required', 'string', 'in:company,employee,vehicle'],
            'documentable_id' => ['nullable', 'integer'],
            'integration_notes' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:'.config('services.documents.upload_max_kb')],
        ]);

        $template = DocumentTemplate::query()
            ->with('section')
            ->findOrFail($data['template_id']);

        $this->assertTemplateMatchesType($template, $data['documentable_type']);

        $documentable = $this->resolveDocumentable(
            $request,
            $data['documentable_type'],
            $data['documentable_id'] ?? null,
        );

        $parent = $this->currentDocument($documentable->documents()
            ->whereNull('parent_uploaded_document_id')
            ->where('template_id', $template->id)
            ->whereNull('subtemplate_id')
            ->latest('updated_at')
            ->get());

        abort_unless($parent instanceof UploadedDocument && $parent->status === 'approved' && ! $parent->isExpired(), 422, 'Puoi inviare integrazioni solo per un documento approvato e non scaduto.');

        $documents = collect($request->file('files'))
            ->map(function (UploadedFile $file) use ($documentable, $template, $parent, $data, $request): UploadedDocument {
                $path = $file->store(
                    "uploaded-documents/{$request->user()->id}/{$data['documentable_type']}/integrazioni",
                    'public',
                );

                $document = $documentable->documents()->create([
                    'template_id' => $template->id,
                    'parent_uploaded_document_id' => $parent->id,
                    'integration_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'integration_notes' => $data['integration_notes'] ?? null,
                    'file_path' => $path,
                    'status' => 'pending',
                    'has_expiry' => false,
                ]);

                AuditLog::record('document.integration_uploaded', $document, 'Integrazione documento caricata', [
                    'template' => $template->name,
                    'parent_document_id' => $parent->id,
                    'integration_name' => $document->integration_name,
                    'notes' => $data['integration_notes'] ?? null,
                    'type' => $data['documentable_type'],
                ], actor: $request->user());

                $this->telegramNotifier->notifyDocumentIntegrationUploaded($document->fresh(['template.section', 'documentable', 'parentDocument']));

                return $document;
            })
            ->values();

        return response()->json([
            'documents' => $documents
                ->map(fn (UploadedDocument $document): array => $this->uploadedDocumentPayload($document->fresh(['template', 'parentDocument'])))
                ->all(),
        ], 201);
    }

    private function storeDocument(Model $documentable, DocumentTemplate $template, ?DocumentSubtemplate $subtemplate, Collection $files, string $type, int $companyId, bool $hasExpiry, ?string $expiryDate, ?string $internalExpiryName, ?string $internalExpiryDate): UploadedDocument
    {
        /** @var UploadedFile $firstFile */
        $firstFile = $files->first();

        $path = $firstFile->store(
            "uploaded-documents/{$companyId}/{$type}",
            'public',
        );

        $document = $documentable->documents()
            ->where('template_id', $template->id)
            ->where('subtemplate_id', $subtemplate?->id)
            ->whereNull('parent_uploaded_document_id')
            ->where(function ($query): void {
                $today = now()->toDateString();

                $query
                    ->where('status', '!=', 'approved')
                    ->orWhere(function ($query) use ($today): void {
                        $query
                            ->where('status', 'approved')
                            ->where(function ($query) use ($today): void {
                                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today);
                            })
                            ->where(function ($query) use ($today): void {
                                $query->whereNull('internal_expiry_date')->orWhereDate('internal_expiry_date', '>=', $today);
                            });
                    });
            })
            ->latest('updated_at')
            ->first();

        $payload = [
            'template_id' => $template->id,
            'subtemplate_id' => $subtemplate?->id,
            'file_path' => $path,
            'status' => 'pending',
            'has_expiry' => $hasExpiry,
            'expiry_date' => $hasExpiry ? $expiryDate : null,
            'internal_expiry_name' => filled($internalExpiryName) && filled($internalExpiryDate) ? $internalExpiryName : null,
            'internal_expiry_date' => filled($internalExpiryName) && filled($internalExpiryDate) ? $internalExpiryDate : null,
            'approved_at' => null,
            'admin_notes' => null,
        ];

        if ($document instanceof UploadedDocument) {
            $document->attachments()->get()->each->delete();

            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
        }

        $rootDocument = $document instanceof UploadedDocument
            ? tap($document)->update($payload)
            : $documentable->documents()->create($payload);

        if ($subtemplate && $files->count() > 1) {
            $files
                ->slice(1)
                ->each(function (UploadedFile $file) use ($documentable, $template, $subtemplate, $type, $companyId, $rootDocument): void {
                    $documentable->documents()->create([
                        'template_id' => $template->id,
                        'subtemplate_id' => $subtemplate->id,
                        'parent_uploaded_document_id' => $rootDocument->id,
                        'file_path' => $file->store(
                            "uploaded-documents/{$companyId}/{$type}",
                            'public',
                        ),
                        'status' => 'pending',
                        'has_expiry' => false,
                        'integration_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    ]);
                });
        }

        return $rootDocument;
    }

    private function documentsPayload(string $sectionSlug, Model $documentable): array
    {
        $section = Section::query()
            ->where('slug', $sectionSlug)
            ->with(['documentTemplates' => fn ($query) => $query
                ->with(['subtemplates', 'category'])
                ->orderBy('name')])
            ->firstOrFail();

        $uploadedDocuments = $documentable->documents()
            ->whereNull('parent_uploaded_document_id')
            ->with(['template', 'subtemplate', 'integrations', 'attachments'])
            ->latest('updated_at')
            ->get()
            ->groupBy(fn (UploadedDocument $document): string => $this->documentKey($document->template_id, $document->subtemplate_id));

        $exemptions = $documentable->documentExemptions()
            ->with(['template', 'subtemplate'])
            ->get()
            ->groupBy(fn (DocumentExemption $exemption): string => $this->documentKey($exemption->template_id, $exemption->subtemplate_id));

        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'slug' => $section->slug,
            ],
            'documents' => $section->documentTemplates
                ->filter(fn (DocumentTemplate $template): bool => ($exemptions->get($this->documentKey($template->id))?->first()?->status ?? null) !== 'approved')
                ->map(function (DocumentTemplate $template) use ($uploadedDocuments, $exemptions): array {
                    $document = $this->currentDocument($uploadedDocuments->get($this->documentKey($template->id)));
                    $exemption = $exemptions->get($this->documentKey($template->id))?->first();
                    $status = $document?->effectiveStatus() ?? 'missing';

                    if ($exemption?->status === 'pending') {
                        $status = 'exemption_pending';
                    }

                    return [
                        'template' => [
                            'id' => $template->id,
                            'name' => $template->name,
                            'is_required' => $template->is_required,
                            'description' => $template->description,
                            'category' => $template->category ? [
                                'id' => $template->category->id,
                                'name' => $template->category->name,
                                'description' => $template->category->description,
                                'sort_order' => $template->category->sort_order,
                            ] : null,
                        ],
                        'uploaded_document' => $document ? $this->uploadedDocumentPayload($document) : null,
                        'exemption' => $exemption ? $this->exemptionPayload($exemption) : null,
                        'status' => $status,
                        'subdocuments' => $template->subtemplates
                            ->filter(fn (DocumentSubtemplate $subtemplate): bool => ($exemptions->get($this->documentKey($template->id, $subtemplate->id))?->first()?->status ?? null) !== 'approved')
                            ->map(function (DocumentSubtemplate $subtemplate) use ($template, $uploadedDocuments, $exemptions): array {
                                $document = $this->currentDocument($uploadedDocuments->get($this->documentKey($template->id, $subtemplate->id)));
                                $exemption = $exemptions->get($this->documentKey($template->id, $subtemplate->id))?->first();
                                $status = $document?->effectiveStatus() ?? 'missing';

                                if ($exemption?->status === 'pending') {
                                    $status = 'exemption_pending';
                                }

                                return [
                                    'template' => [
                                        'id' => $template->id,
                                        'name' => $template->name,
                                    ],
                                    'subtemplate' => [
                                        'id' => $subtemplate->id,
                                        'name' => $subtemplate->name,
                                        'is_required' => $subtemplate->is_required,
                                        'description' => $subtemplate->description,
                                    ],
                                    'uploaded_document' => $document ? $this->uploadedDocumentPayload($document) : null,
                                    'exemption' => $exemption ? $this->exemptionPayload($exemption) : null,
                                    'status' => $status,
                                ];
                            })
                            ->values(),
                    ];
                })
                ->values(),
        ];
    }

    private function exemptionPayload(DocumentExemption $exemption): array
    {
        return [
            'id' => $exemption->id,
            'template_id' => $exemption->template_id,
            'subtemplate_id' => $exemption->subtemplate_id,
            'status' => $exemption->status,
            'requested_reason' => $exemption->requested_reason,
            'admin_notes' => $exemption->admin_notes,
            'reviewed_at' => $exemption->reviewed_at?->toIso8601String(),
            'created_at' => $exemption->created_at?->toIso8601String(),
            'updated_at' => $exemption->updated_at?->toIso8601String(),
        ];
    }

    private function approvedExemptionExists(Model $documentable, DocumentTemplate $template, ?DocumentSubtemplate $subtemplate = null): bool
    {
        return $documentable->documentExemptions()
            ->where('template_id', $template->id)
            ->where('subtemplate_id', $subtemplate?->id)
            ->where('status', 'approved')
            ->exists();
    }

    private function uploadedDocumentPayload(UploadedDocument $document): array
    {
        return [
            'id' => $document->id,
            'template_id' => $document->template_id,
            'subtemplate_id' => $document->subtemplate_id,
            'file_path' => $document->file_path,
            'file_url' => $document->file_url,
            'file_name' => filled($document->file_path) ? basename($document->file_path) : null,
            'status' => $document->status,
            'effective_status' => $document->effectiveStatus(),
            'is_expired' => $document->isExpired(),
            'has_expiry' => $document->has_expiry,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'internal_expiry_name' => $document->internal_expiry_name,
            'internal_expiry_date' => $document->internal_expiry_date?->toDateString(),
            'approved_at' => $document->approved_at?->toIso8601String(),
            'admin_notes' => $document->admin_notes,
            'parent_uploaded_document_id' => $document->parent_uploaded_document_id,
            'integration_name' => $document->integration_name,
            'integration_notes' => $document->integration_notes,
            'is_integration' => $document->isIntegration(),
            'is_attachment' => $document->isAttachment(),
            'attachments' => $document->relationLoaded('attachments')
                ? $document->attachments->map(fn (UploadedDocument $attachment): array => $this->uploadedDocumentPayload($attachment))->all()
                : [],
            'integrations' => $document->relationLoaded('integrations')
                ? $document->integrations->map(fn (UploadedDocument $integration): array => $this->uploadedDocumentPayload($integration))->all()
                : [],
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    private function resolveDocumentable(Request $request, string $type, ?int $id): Model
    {
        return match ($type) {
            'company' => $request->user(),
            'employee' => Employee::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($id),
            'vehicle' => Vehicle::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($id),
        };
    }

    private function assertTemplateMatchesType(DocumentTemplate $template, string $type): void
    {
        $expectedType = match ($template->section->slug) {
            'societa' => 'company',
            'dipendenti' => 'employee',
            'veicoli' => 'vehicle',
            default => null,
        };

        abort_unless($expectedType === $type, 422, 'Template non coerente con la sezione selezionata.');
    }

    private function resolveSubtemplate(DocumentTemplate $template, ?int $subtemplateId): ?DocumentSubtemplate
    {
        if (blank($subtemplateId)) {
            return null;
        }

        return $template->subtemplates()
            ->whereKey($subtemplateId)
            ->firstOrFail();
    }

    private function documentKey(int $templateId, ?int $subtemplateId = null): string
    {
        return $templateId.':'.($subtemplateId ?: 'parent');
    }

    private function resolveUploadFiles(Request $request, ?DocumentSubtemplate $subtemplate): Collection
    {
        $files = collect($request->file('files', []))
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->values();

        if ($subtemplate) {
            if ($files->isEmpty() && $request->file('file') instanceof UploadedFile) {
                $files = collect([$request->file('file')]);
            }

            abort_if($files->isEmpty(), 422, 'Carica almeno un file per il sottodocumento.');

            return $files;
        }

        if ($files->count() > 1) {
            abort(422, 'Per questo documento puoi caricare un solo file alla volta.');
        }

        if ($files->isNotEmpty()) {
            return collect([$files->first()]);
        }

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'Carica un file valido.');

        return collect([$file]);
    }

    private function currentDocument(?\Illuminate\Support\Collection $documents): ?UploadedDocument
    {
        if (! $documents || $documents->isEmpty()) {
            return null;
        }

        return $documents->first(fn (UploadedDocument $document): bool => ! $document->isExpired())
            ?: $documents->first();
    }
}
