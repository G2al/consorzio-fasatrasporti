<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeadlineReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{bucket: string, title: string, items: array<int, array{owner: string, document: string, label: string, date: string, days: int}>}>  $groups
     */
    public function __construct(
        public User $company,
        public array $groups,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Documenti in scadenza - '.$this->company->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.documents.deadline-reminder',
        );
    }
}
