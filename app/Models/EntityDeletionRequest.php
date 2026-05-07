<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityDeletionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'deletable_type',
        'deletable_id',
        'snapshot_label',
        'snapshot_secondary',
        'requested_reason',
        'status',
        'admin_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function deletable(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeKey(): string
    {
        return match ($this->deletable_type) {
            Employee::class => 'employee',
            Vehicle::class => 'vehicle',
            default => 'entity',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->typeKey()) {
            'employee' => 'Dipendente',
            'vehicle' => 'Veicolo',
            default => 'Elemento',
        };
    }

    public function targetPath(): string
    {
        return match ($this->typeKey()) {
            'employee' => 'dipendenti.html',
            'vehicle' => 'veicoli.html',
            default => 'index.html',
        };
    }

    public function reviewLabel(): string
    {
        return $this->typeLabel().' - '.$this->snapshot_label;
    }

    public function approve(?User $actor = null): void
    {
        $this->update([
            'status' => 'approved',
            'admin_notes' => null,
            'reviewed_at' => now(),
        ]);

        AuditLog::record(
            'entity_deletion_request.approved',
            $this,
            'Richiesta eliminazione approvata',
            [
                'type' => $this->typeKey(),
                'name' => $this->snapshot_label,
                'details' => $this->snapshot_secondary,
                'requested_reason' => $this->requested_reason,
            ],
            actor: $actor,
            company: $this->company,
        );

        $deletable = $this->deletable;

        if (! $deletable) {
            return;
        }

        if ($deletable instanceof Employee) {
            AuditLog::record('employee.deleted', $deletable, 'Dipendente eliminato', [
                'name' => trim("{$deletable->first_name} {$deletable->last_name}"),
                'details' => $deletable->phone,
                'via_request' => true,
            ], actor: $actor, company: $this->company);
        }

        if ($deletable instanceof Vehicle) {
            AuditLog::record('vehicle.deleted', $deletable, 'Veicolo eliminato', [
                'plate' => $deletable->plate,
                'capacity' => $deletable->capacity,
                'via_request' => true,
            ], actor: $actor, company: $this->company);
        }

        $deletable->delete();
    }

    public function reject(string $notes, ?User $actor = null): void
    {
        $this->update([
            'status' => 'rejected',
            'admin_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        AuditLog::record(
            'entity_deletion_request.rejected',
            $this,
            'Richiesta eliminazione respinta',
            [
                'type' => $this->typeKey(),
                'name' => $this->snapshot_label,
                'details' => $this->snapshot_secondary,
                'requested_reason' => $this->requested_reason,
                'notes' => $notes,
            ],
            actor: $actor,
            company: $this->company,
        );
    }
}
