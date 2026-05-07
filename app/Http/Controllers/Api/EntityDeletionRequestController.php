<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EntityDeletionRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityDeletionRequestController extends Controller
{
    public function storeEmployee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);

        return $this->store($request, $employee);
    }

    public function storeVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicle($request, $vehicle);

        return $this->store($request, $vehicle);
    }

    private function store(Request $request, Employee|Vehicle $entity): JsonResponse
    {
        /** @var User $company */
        $company = $request->user();

        $data = $request->validate([
            'requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $pendingExists = $entity->deletionRequests()
            ->where('status', 'pending')
            ->exists();

        abort_if($pendingExists, 422, 'Esiste gia una richiesta di eliminazione in attesa.');

        $deletionRequest = EntityDeletionRequest::query()->create([
            'company_id' => $company->id,
            'deletable_type' => $entity::class,
            'deletable_id' => $entity->id,
            'snapshot_label' => $entity instanceof Employee
                ? trim("{$entity->first_name} {$entity->last_name}")
                : $entity->plate,
            'snapshot_secondary' => $entity instanceof Employee
                ? ($entity->phone ?: null)
                : ($entity->capacity.' posti'),
            'requested_reason' => $data['requested_reason'] ?? null,
            'status' => 'pending',
        ]);

        AuditLog::record(
            'entity_deletion_request.requested',
            $deletionRequest,
            'Richiesta eliminazione inviata',
            [
                'type' => $deletionRequest->typeKey(),
                'name' => $deletionRequest->snapshot_label,
                'details' => $deletionRequest->snapshot_secondary,
                'requested_reason' => $deletionRequest->requested_reason,
            ],
            actor: $company,
            company: $company,
        );

        return response()->json([
            'message' => 'Richiesta di eliminazione inviata.',
            'request' => $this->payload($deletionRequest),
        ], 201);
    }

    private function payload(EntityDeletionRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->typeKey(),
            'type_label' => $request->typeLabel(),
            'status' => $request->status,
            'snapshot_label' => $request->snapshot_label,
            'snapshot_secondary' => $request->snapshot_secondary,
            'requested_reason' => $request->requested_reason,
            'admin_notes' => $request->admin_notes,
            'created_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'target' => $request->targetPath(),
        ];
    }

    private function authorizeEmployee(Request $request, Employee $employee): void
    {
        abort_unless($employee->user_id === $request->user()->id, 404);
    }

    private function authorizeVehicle(Request $request, Vehicle $vehicle): void
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
    }
}
