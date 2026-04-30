<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MissingDocumentsReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{owner: string, document: string, reason: string, expiry?: string|null}>  $items
     */
    public function __construct(
        public User $company,
        public string $sectionLabel,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Documenti mancanti - '.$this->sectionLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.documents.missing-report',
        );
    }
}
