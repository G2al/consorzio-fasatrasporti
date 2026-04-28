<?php

namespace App\Mail;

use App\Models\UploadedDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UploadedDocument $document,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Documento respinto - '.$this->documentName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.documents.rejected',
            with: [
                'documentName' => $this->documentName(),
            ],
        );
    }

    private function documentName(): string
    {
        return $this->document->subtemplate
            ? $this->document->template->name.' / '.$this->document->subtemplate->name
            : $this->document->template->name;
    }
}
