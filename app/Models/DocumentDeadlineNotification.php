<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentDeadlineNotification extends Model
{
    protected $fillable = [
        'uploaded_document_id',
        'channel',
        'deadline_type',
        'bucket',
        'deadline_date',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(UploadedDocument::class, 'uploaded_document_id');
    }
}
