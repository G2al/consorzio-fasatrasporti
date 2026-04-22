<?php

namespace App\Models;

use App\Notifications\DocumentStatusChanged;
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
        'documentable_id',
        'documentable_type',
        'file_path',
        'status',
        'expiry_date',
        'approved_at',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
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

    public function versions(): HasMany
    {
        return $this->hasMany(UploadedDocumentVersion::class);
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
            if ($document->status === 'approved' && blank($document->approved_at)) {
                $document->approved_at = now();
            }

            if ($document->status !== 'approved') {
                $document->approved_at = null;
            }
        });

        static::deleting(function (UploadedDocument $document): void {
            $document->versions()->get()->each->delete();

            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
        });

        static::updated(function (UploadedDocument $document): void {
            if (! $document->wasChanged('status') || ! in_array($document->status, ['approved', 'rejected'], true)) {
                return;
            }

            $document->loadMissing(['template.section', 'documentable']);
            $document->companyUser()?->notify(new DocumentStatusChanged($document));
        });
    }
}
