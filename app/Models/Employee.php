<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone',
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

    public function deletionRequests(): MorphMany
    {
        return $this->morphMany(EntityDeletionRequest::class, 'deletable');
    }

    public function latestDeletionRequest(): MorphOne
    {
        return $this->morphOne(EntityDeletionRequest::class, 'deletable')->latestOfMany();
    }

    public function uploadedDocuments(): MorphMany
    {
        return $this->documents();
    }

    protected static function booted(): void
    {
        static::deleting(function (Employee $employee): void {
            $employee->documents()->get()->each->delete();
            $employee->documentExemptions()->delete();
        });
    }
}
