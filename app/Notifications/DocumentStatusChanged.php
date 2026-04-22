<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentStatusChanged extends Notification
{
    use Queueable;

    public function __construct(private readonly UploadedDocument $document)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->document->loadMissing(['template.section', 'documentable']);
        $isApproved = $document->status === 'approved';
        $subject = $isApproved ? 'Documento approvato' : 'Documento respinto';
        $owner = $this->documentableLabel($document);

        $message = (new MailMessage)
            ->subject($subject.' - '.$document->template->name)
            ->greeting('Ciao '.$notifiable->name)
            ->line("Documento: {$document->template->name}")
            ->line("Sezione: {$document->template->section->name}")
            ->line("Riferimento: {$owner}")
            ->line('Stato: '.($isApproved ? 'Approvato' : 'Respinto'));

        if ($isApproved && $document->expiry_date) {
            $message->line('Scadenza: '.$document->expiry_date->format('d/m/Y'));
        }

        if (! $isApproved && filled($document->admin_notes)) {
            $message->line('Note: '.$document->admin_notes);
        }

        return $message->line('Puoi verificare i dettagli nella dashboard documentale.');
    }

    private function documentableLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof Vehicle => "{$documentable->brand_model} ({$documentable->plate})",
            default => 'Elemento eliminato',
        };
    }
}
