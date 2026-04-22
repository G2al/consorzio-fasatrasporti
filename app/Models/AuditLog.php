<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'auditable_id',
        'auditable_type',
        'action',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function labelForAction(string $action): string
    {
        return match ($action) {
            'company.registered' => 'Societa registrata',
            'company.profile_updated' => 'Profilo societa aggiornato',
            'company.password_updated' => 'Password aggiornata',
            'employee.created' => 'Dipendente creato',
            'employee.updated' => 'Dipendente aggiornato',
            'employee.deleted' => 'Dipendente eliminato',
            'vehicle.created' => 'Veicolo creato',
            'vehicle.updated' => 'Veicolo aggiornato',
            'vehicle.deleted' => 'Veicolo eliminato',
            'document.uploaded' => 'Documento caricato',
            'document.bulk_uploaded' => 'Caricamento multiplo',
            'document.approved' => 'Documento approvato',
            'document.rejected' => 'Documento respinto',
            default => str($action)->replace(['.', '_'], ' ')->headline()->toString(),
        };
    }

    public function getReadableActionAttribute(): string
    {
        return static::labelForAction($this->action);
    }

    public function getReadableActorAttribute(): string
    {
        return $this->user?->name ?: 'Sistema';
    }

    public function getReadableSubjectAttribute(): string
    {
        $auditable = $this->auditable;

        if ($auditable instanceof UploadedDocument) {
            $auditable->loadMissing(['template', 'documentable']);

            return trim($auditable->template?->name.' - '.$this->documentableLabel($auditable));
        }

        if ($auditable instanceof Employee) {
            return trim("{$auditable->first_name} {$auditable->last_name}");
        }

        if ($auditable instanceof Vehicle) {
            return "{$auditable->plate} - {$auditable->brand_model}";
        }

        if ($auditable instanceof User) {
            return $auditable->name;
        }

        return $this->metadataSubject();
    }

    public function getReadableSummaryAttribute(): string
    {
        $parts = array_filter([
            $this->readable_action,
            $this->readable_subject,
        ]);

        return implode(' - ', $parts);
    }

    public function getReadableMetadataAttribute(): string
    {
        $metadata = $this->metadata;

        if (blank($metadata) || ! is_array($metadata)) {
            return '-';
        }

        $parts = [];

        if (! empty($metadata['template'])) {
            $parts[] = 'Documento: '.$metadata['template'];
        }

        if (! empty($metadata['type'])) {
            $parts[] = 'Area: '.$this->typeLabel((string) $metadata['type']);
        }

        if (! empty($metadata['expiry_date'])) {
            $parts[] = 'Scadenza: '.date('d/m/Y', strtotime((string) $metadata['expiry_date']));
        }

        if (! empty($metadata['notes'])) {
            $parts[] = 'Note: '.$metadata['notes'];
        }

        if (! empty($metadata['name'])) {
            $parts[] = 'Nome: '.$metadata['name'];
        }

        if (! empty($metadata['plate'])) {
            $parts[] = 'Targa: '.$metadata['plate'];
        }

        if (! empty($metadata['brand_model'])) {
            $parts[] = 'Mezzo: '.$metadata['brand_model'];
        }

        if (isset($metadata['before'], $metadata['after']) && is_array($metadata['before']) && is_array($metadata['after'])) {
            $changed = collect($metadata['after'])
                ->filter(fn ($value, string $key): bool => ($metadata['before'][$key] ?? null) !== $value)
                ->keys()
                ->map(fn (string $key): string => $this->fieldLabel($key))
                ->implode(', ');

            if ($changed !== '') {
                $parts[] = 'Campi modificati: '.$changed;
            }
        }

        return $parts ? implode(' | ', $parts) : (json_encode($metadata, JSON_UNESCAPED_UNICODE) ?: '-');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?Model $auditable = null, string $description = '', array $metadata = [], ?User $actor = null, ?User $company = null): self
    {
        $actor ??= auth('admin')->user() ?: request()?->user();

        if (! $company) {
            $company = match (true) {
                $auditable instanceof UploadedDocument => $auditable->companyUser(),
                $auditable instanceof UploadedDocumentVersion => $auditable->uploadedDocument?->companyUser(),
                $auditable instanceof Employee,
                $auditable instanceof Vehicle => $auditable->user,
                $auditable instanceof User && $auditable->role === 'company' => $auditable,
                $actor instanceof User && $actor->role === 'company' => $actor,
                default => null,
            };
        }

        return static::query()->create([
            'user_id' => $actor?->id,
            'company_id' => $company?->id,
            'auditable_id' => $auditable?->getKey(),
            'auditable_type' => $auditable ? $auditable::class : null,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    private function metadataSubject(): string
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return '';
        }

        if (! empty($metadata['name'])) {
            return (string) $metadata['name'];
        }

        if (! empty($metadata['plate']) || ! empty($metadata['brand_model'])) {
            return trim(($metadata['plate'] ?? '').' - '.($metadata['brand_model'] ?? ''), ' -');
        }

        if (! empty($metadata['template'])) {
            return (string) $metadata['template'];
        }

        return '';
    }

    private function documentableLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof Vehicle => "{$documentable->brand_model} ({$documentable->plate})",
            default => 'Elemento eliminato',
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'company' => 'Societa',
            'employee' => 'Dipendenti',
            'vehicle' => 'Veicoli',
            default => $type,
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => 'ragione sociale',
            'responsible_name' => 'responsabile',
            'vat_number' => 'partita IVA',
            'email' => 'email',
            default => str($field)->replace('_', ' ')->toString(),
        };
    }
}
