<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentExemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'subtemplate_id',
        'exemptable_id',
        'exemptable_type',
        'status',
        'requested_reason',
        'admin_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
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

    public function exemptable(): MorphTo
    {
        return $this->morphTo();
    }

    public function companyUser(): ?User
    {
        $exemptable = $this->exemptable;

        return match (true) {
            $exemptable instanceof User => $exemptable,
            $exemptable instanceof Employee,
            $exemptable instanceof Vehicle => $exemptable->user,
            default => null,
        };
    }
}
