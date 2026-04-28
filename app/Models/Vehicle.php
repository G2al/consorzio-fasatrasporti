<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plate',
        'capacity',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(UploadedDocument::class, 'documentable');
    }

    public function documentExemptions(): MorphMany
    {
        return $this->morphMany(DocumentExemption::class, 'exemptable');
    }

    public function uploadedDocuments(): MorphMany
    {
        return $this->documents();
    }

    protected static function booted(): void
    {
        static::deleting(function (Vehicle $vehicle): void {
            $vehicle->documents()->get()->each->delete();
            $vehicle->documentExemptions()->delete();
        });
    }
}
