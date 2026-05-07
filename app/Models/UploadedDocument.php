<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class UploadedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'subtemplate_id',
        'parent_uploaded_document_id',
        'integration_name',
        'integration_notes',
        'documentable_id',
        'documentable_type',
        'file_path',
        'status',
        'has_expiry',
        'expiry_date',
        'internal_expiry_name',
        'internal_expiry_date',
        'approved_at',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'internal_expiry_date' => 'date',
            'has_expiry' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function subtemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentSubtemplate::class, 'subtemplate_id');
    }

    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(UploadedDocument::class, 'parent_uploaded_document_id');
    }

    public function childDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class, 'parent_uploaded_document_id')
            ->latest('created_at');
    }

    public function integrations(): HasMany
    {
        return $this->childDocuments()
            ->whereNull('subtemplate_id')
            ->whereNotNull('integration_name')
            ->latest('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->childDocuments()
            ->whereNotNull('subtemplate_id')
            ->latest('created_at');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function companyUser(): ?User
    {
        $documentable = $this->documentable;

        return match (true) {
            $documentable instanceof User => $documentable,
            $documentable instanceof Employee,
            $documentable instanceof Vehicle => $documentable->user,
            default => null,
        };
    }

    public function getFileUrlAttribute(): string
    {
        return filled($this->file_path)
            ? asset('storage/'.$this->file_path)
            : '';
    }

    public function isExpired(): bool
    {
        if ($this->status !== 'approved' || $this->isIntegration()) {
            return false;
        }

        $today = now()->startOfDay();

        return ($this->expiry_date && $this->expiry_date->copy()->startOfDay()->lt($today))
            || ($this->internal_expiry_date && $this->internal_expiry_date->copy()->startOfDay()->lt($today));
    }

    public function effectiveStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public function isIntegration(): bool
    {
        return filled($this->parent_uploaded_document_id) && blank($this->subtemplate_id);
    }

    public function isAttachment(): bool
    {
        return filled($this->parent_uploaded_document_id) && filled($this->subtemplate_id);
    }

    protected static function booted(): void
    {
        static::saving(function (UploadedDocument $document): void {
            if (filled($document->subtemplate_id) || filled($document->parent_uploaded_document_id)) {
                $document->has_expiry = false;
                $document->expiry_date = null;
                $document->internal_expiry_name = null;
                $document->internal_expiry_date = null;
            }

            if (! $document->has_expiry) {
                $document->expiry_date = null;
            }

            if (blank($document->internal_expiry_name) || blank($document->internal_expiry_date)) {
                $document->internal_expiry_name = null;
                $document->internal_expiry_date = null;
            }

            if ($document->status === 'approved' && blank($document->approved_at)) {
                $document->approved_at = now();
            }

            if ($document->status !== 'approved') {
                $document->approved_at = null;
            }
        });

        static::deleting(function (UploadedDocument $document): void {
            $document->childDocuments()->get()->each->delete();

            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
        });

    }
}
