<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'name',
        'description',
        'sort_order',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class, 'category_id');
    }
}
