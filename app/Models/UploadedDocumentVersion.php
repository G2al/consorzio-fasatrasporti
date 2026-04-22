<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class UploadedDocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_document_id',
        'template_id',
        'file_path',
        'status',
        'expiry_date',
        'approved_at',
        'admin_notes',
        'versioned_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'approved_at' => 'datetime',
            'versioned_at' => 'datetime',
        ];
    }

    public function uploadedDocument(): BelongsTo
    {
        return $this->belongsTo(UploadedDocument::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function getFileUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }

    protected static function booted(): void
    {
        static::deleting(function (UploadedDocumentVersion $version): void {
            if ($version->file_path) {
                Storage::disk('public')->delete($version->file_path);
            }
        });
    }
}
