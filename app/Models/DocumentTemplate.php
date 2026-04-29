<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'category_id',
        'name',
        'is_required',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class, 'template_id');
    }

    public function subtemplates(): HasMany
    {
        return $this->hasMany(DocumentSubtemplate::class, 'template_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
