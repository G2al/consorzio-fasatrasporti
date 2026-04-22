<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requiredDocumentsCount = $this->requiredDocumentsCount();

        $employees = Employee::query()
            ->where('user_id', $request->user()->id)
            ->withCount('documents')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee): array => $this->payload($employee, $requiredDocumentsCount));

        return response()->json([
            'employees' => $employees,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'tax_code' => ['required', 'string', 'max:255'],
        ]);

        $employee = $request->user()->employees()->create($data);
        AuditLog::record('employee.created', $employee, 'Dipendente creato', actor: $request->user());

        return response()->json([
            'employee' => $this->payload($employee->loadCount('documents'), $this->requiredDocumentsCount()),
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOwner($request, $employee);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'tax_code' => ['required', 'string', 'max:255'],
        ]);

        $employee->update($data);
        AuditLog::record('employee.updated', $employee, 'Dipendente aggiornato', actor: $request->user());

        return response()->json([
            'employee' => $this->payload($employee->loadCount('documents'), $this->requiredDocumentsCount()),
        ]);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOwner($request, $employee);

        AuditLog::record('employee.deleted', $employee, 'Dipendente eliminato', [
            'name' => trim("{$employee->first_name} {$employee->last_name}"),
        ], actor: $request->user());
        $employee->delete();

        return response()->json([
            'message' => 'Dipendente eliminato.',
        ]);
    }

    private function authorizeOwner(Request $request, Employee $employee): void
    {
        abort_unless($employee->user_id === $request->user()->id, 404);
    }

    private function payload(Employee $employee, ?int $requiredDocumentsCount = null): array
    {
        return [
            'id' => $employee->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'tax_code' => $employee->tax_code,
            'documents_count' => $employee->documents_count ?? $employee->documents()->count(),
            'required_documents_count' => $requiredDocumentsCount ?? $this->requiredDocumentsCount(),
        ];
    }

    private function requiredDocumentsCount(): int
    {
        return DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'dipendenti'))
            ->count();
    }
}
