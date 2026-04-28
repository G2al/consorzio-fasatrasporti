<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSubtemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'name',
        'is_required',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentSubtemplate $subtemplate): void {
            $subtemplate->is_required = false;
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class, 'subtemplate_id');
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(DocumentExemption::class, 'subtemplate_id');
    }
}
