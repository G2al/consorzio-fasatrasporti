<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requiredDocumentsCount = $this->requiredDocumentsCount();

        $vehicles = Vehicle::query()
            ->where('user_id', $request->user()->id)
            ->withCount('documents')
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
            'brand_model' => ['required', 'string', 'max:255'],
            'plate' => ['required', 'string', 'max:255'],
        ]);

        $vehicle = $request->user()->vehicles()->create($data);
        AuditLog::record('vehicle.created', $vehicle, 'Veicolo creato', actor: $request->user());

        return response()->json([
            'vehicle' => $this->payload($vehicle->loadCount('documents'), $this->requiredDocumentsCount()),
        ], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeOwner($request, $vehicle);

        $data = $request->validate([
            'brand_model' => ['required', 'string', 'max:255'],
            'plate' => ['required', 'string', 'max:255'],
        ]);

        $vehicle->update($data);
        AuditLog::record('vehicle.updated', $vehicle, 'Veicolo aggiornato', actor: $request->user());

        return response()->json([
            'vehicle' => $this->payload($vehicle->loadCount('documents'), $this->requiredDocumentsCount()),
        ]);
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeOwner($request, $vehicle);

        AuditLog::record('vehicle.deleted', $vehicle, 'Veicolo eliminato', [
            'plate' => $vehicle->plate,
            'brand_model' => $vehicle->brand_model,
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
        return [
            'id' => $vehicle->id,
            'brand_model' => $vehicle->brand_model,
            'plate' => $vehicle->plate,
            'documents_count' => $vehicle->documents_count ?? $vehicle->documents()->count(),
            'required_documents_count' => $requiredDocumentsCount ?? $this->requiredDocumentsCount(),
        ];
    }

    private function requiredDocumentsCount(): int
    {
        return DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'veicoli'))
            ->count();
    }
}
