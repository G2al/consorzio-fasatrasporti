<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
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
            'documentable_type' => ['required', 'string', 'in:company,employee,vehicle'],
            'documentable_id' => ['nullable', 'integer'],
            'has_expiry' => ['required', 'boolean'],
            'expiry_date' => ['nullable', 'required_if:has_expiry,1,true,on', 'date'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:20480'],
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

        $document = $this->storeDocument(
            $documentable,
            $template,
            $request->file('file'),
            $data['documentable_type'],
            $request->user()->id,
            $request->boolean('has_expiry'),
            $data['expiry_date'] ?? null,
        );
        AuditLog::record('document.uploaded', $document, 'Documento caricato', [
            'template' => $template->name,
            'type' => $data['documentable_type'],
            'expiry_date' => $data['expiry_date'] ?? null,
        ], actor: $request->user());

        return response()->json([
            'document' => $this->uploadedDocumentPayload($document->fresh(['template'])),
        ], 201);
    }

    private function storeDocument(Model $documentable, DocumentTemplate $template, UploadedFile $file, string $type, int $companyId, bool $hasExpiry, ?string $expiryDate): UploadedDocument
    {
        $path = $file->store(
            "uploaded-documents/{$companyId}/{$type}",
            'public',
        );

        $document = $documentable->documents()
            ->where('template_id', $template->id)
            ->first();

        $payload = [
            'template_id' => $template->id,
            'file_path' => $path,
            'status' => 'pending',
            'has_expiry' => $hasExpiry,
            'expiry_date' => $hasExpiry ? $expiryDate : null,
            'approved_at' => null,
            'admin_notes' => null,
        ];

        if ($document instanceof UploadedDocument && $document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        return $document instanceof UploadedDocument
            ? tap($document)->update($payload)
            : $documentable->documents()->create($payload);
    }

    private function documentsPayload(string $sectionSlug, Model $documentable): array
    {
        $section = Section::query()
            ->where('slug', $sectionSlug)
            ->with(['documentTemplates' => fn ($query) => $query->orderBy('name')])
            ->firstOrFail();

        $uploadedDocuments = $documentable->documents()
            ->with('template')
            ->get()
            ->keyBy('template_id');

        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'slug' => $section->slug,
            ],
            'documents' => $section->documentTemplates
                ->map(function (DocumentTemplate $template) use ($uploadedDocuments): array {
                    $document = $uploadedDocuments->get($template->id);

                    return [
                        'template' => [
                            'id' => $template->id,
                            'name' => $template->name,
                            'is_required' => $template->is_required,
                            'description' => $template->description,
                        ],
                        'uploaded_document' => $document ? $this->uploadedDocumentPayload($document) : null,
                        'status' => $document?->status ?? 'missing',
                    ];
                })
                ->values(),
        ];
    }

    private function uploadedDocumentPayload(UploadedDocument $document): array
    {
        return [
            'id' => $document->id,
            'template_id' => $document->template_id,
            'file_path' => $document->file_path,
            'file_url' => $document->file_url,
            'status' => $document->status,
            'has_expiry' => $document->has_expiry,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'approved_at' => $document->approved_at?->toIso8601String(),
            'admin_notes' => $document->admin_notes,
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
}
