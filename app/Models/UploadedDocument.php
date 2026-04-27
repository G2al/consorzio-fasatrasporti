<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class UploadedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'documentable_id',
        'documentable_type',
        'file_path',
        'status',
        'has_expiry',
        'expiry_date',
        'approved_at',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'has_expiry' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
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
        return asset('storage/'.$this->file_path);
    }

    protected static function booted(): void
    {
        static::saving(function (UploadedDocument $document): void {
            if (! $document->has_expiry) {
                $document->expiry_date = null;
            }

            if ($document->status === 'approved' && blank($document->approved_at)) {
                $document->approved_at = now();
            }

            if ($document->status !== 'approved') {
                $document->approved_at = null;
            }
        });

        static::deleting(function (UploadedDocument $document): void {
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
        });

    }
}
