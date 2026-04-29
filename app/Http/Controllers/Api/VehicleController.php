<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\UploadedDocument;
use App\Models\Vehicle;
use App\Models\VehicleCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requiredDocumentsCount = $this->requiredDocumentsCount();

        $vehicles = Vehicle::query()
            ->where('user_id', $request->user()->id)
            ->withCount($this->documentStatusCounts())
            ->orderBy('plate')
            ->get()
            ->map(fn (Vehicle $vehicle): array => $this->payload($vehicle, $requiredDocumentsCount));

        return response()->json([
            'vehicles' => $vehicles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', Rule::in($this->capacityValues())],
        ]);

        $vehicle = $request->user()->vehicles()->create($data);
        AuditLog::record('vehicle.created', $vehicle, 'Veicolo creato', actor: $request->user());

        return response()->json([
            'vehicle' => $this->payload($vehicle->loadCount($this->documentStatusCounts()), $this->requiredDocumentsCount()),
        ], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeOwner($request, $vehicle);

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', Rule::in($this->capacityValues())],
        ]);

        $vehicle->update($data);
        AuditLog::record('vehicle.updated', $vehicle, 'Veicolo aggiornato', actor: $request->user());

        return response()->json([
            'vehicle' => $this->payload($vehicle->loadCount($this->documentStatusCounts()), $this->requiredDocumentsCount()),
        ]);
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeOwner($request, $vehicle);

        AuditLog::record('vehicle.deleted', $vehicle, 'Veicolo eliminato', [
            'plate' => $vehicle->plate,
            'capacity' => $vehicle->capacity,
        ], actor: $request->user());
        $vehicle->delete();

        return response()->json([
            'message' => 'Veicolo eliminato.',
        ]);
    }

    private function authorizeOwner(Request $request, Vehicle $vehicle): void
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
    }

    private function payload(Vehicle $vehicle, ?int $requiredDocumentsCount = null): array
    {
        $currentStatuses = $this->currentDocumentStatuses($vehicle);

        return [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'capacity' => $vehicle->capacity,
            'documents_count' => $currentStatuses->reject(fn (string $status): bool => in_array($status, ['missing', 'expired'], true))->count(),
            'approved_documents_count' => $currentStatuses->filter(fn (string $status): bool => $status === 'approved')->count(),
            'pending_documents_count' => $currentStatuses->filter(fn (string $status): bool => $status === 'pending')->count(),
            'rejected_documents_count' => $currentStatuses->filter(fn (string $status): bool => $status === 'rejected')->count(),
            'required_documents_count' => max(($requiredDocumentsCount ?? $this->requiredDocumentsCount()) - $vehicle->documentExemptions()
                ->where('status', 'approved')
                ->whereNull('subtemplate_id')
                ->whereHas('template.section', fn ($query) => $query->where('slug', 'veicoli'))
                ->count(), 0),
        ];
    }

    private function documentStatusCounts(): array
    {
        return [
            'documents',
            'documents as approved_documents_count' => fn ($query) => $query->where('status', 'approved'),
            'documents as pending_documents_count' => fn ($query) => $query->where('status', 'pending'),
            'documents as rejected_documents_count' => fn ($query) => $query->where('status', 'rejected'),
        ];
    }

    private function requiredDocumentsCount(): int
    {
        return DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'veicoli'))
            ->count();
    }

    private function capacityValues(): array
    {
        return VehicleCapacity::query()
            ->orderBy('seats')
            ->pluck('seats')
            ->all() ?: VehicleCapacity::VALUES;
    }

    private function currentDocumentStatuses(Vehicle $vehicle): Collection
    {
        $documents = $vehicle->documents()
            ->whereNull('parent_uploaded_document_id')
            ->latest('updated_at')
            ->get()
            ->whereNull('subtemplate_id')
            ->groupBy('template_id');

        return DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'veicoli'))
            ->get()
            ->map(function (DocumentTemplate $template) use ($documents): string {
                $document = $this->currentDocument($documents->get($template->id));

                return $document?->effectiveStatus() ?? 'missing';
            });
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
