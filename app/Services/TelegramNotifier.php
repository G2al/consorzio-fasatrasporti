<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramNotifier
{
    public function notifyCompanyRegistered(User $company): void
    {
        $this->sendCompanyMessage($company, $this->registrationMessage($company));
    }

    public function notifyCompanyApproved(User $company): void
    {
        $this->sendCompanyMessage($company, $this->approvalMessage($company));
    }

    public function notifyDocumentUploaded(UploadedDocument $document, string $event = 'uploaded'): void
    {
        $document->loadMissing(['template.section', 'subtemplate', 'documentable']);

        $this->sendDocumentMessage($document, $this->documentMessage($document, $event));
    }

    public function notifyDocumentIntegrationUploaded(UploadedDocument $document): void
    {
        $document->loadMissing(['template.section', 'documentable', 'parentDocument']);

        $this->sendDocumentMessage($document, $this->documentIntegrationMessage($document));
    }

    private function sendCompanyMessage(User $company, string $message): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $token = (string) config('services.telegram.bot_token');
        $chatId = (string) config('services.telegram.registration_chat_id');

        if ($token === '' || $chatId === '') {
            return;
        }

        try {
            Http::timeout(8)
                ->retry(2, 300)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'text' => $message,
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Invio notifica Telegram societa non riuscito.', [
                'company_id' => $company->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sendDocumentMessage(UploadedDocument $document, string $message): void
    {
        if (! $this->isDocumentEnabled()) {
            return;
        }

        $token = (string) config('services.telegram.document_bot_token');
        $chatId = (string) config('services.telegram.document_chat_id');

        if ($token === '' || $chatId === '') {
            return;
        }

        try {
            Http::timeout(8)
                ->retry(2, 300)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'text' => $message,
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Invio notifica Telegram documento non riuscito.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function isEnabled(): bool
    {
        if (! (bool) config('services.telegram.registration_enabled')) {
            return false;
        }

        if (app()->runningUnitTests() && ! (bool) config('services.telegram.allow_during_tests')) {
            return false;
        }

        return true;
    }

    private function isDocumentEnabled(): bool
    {
        if (! (bool) config('services.telegram.document_enabled')) {
            return false;
        }

        if (app()->runningUnitTests() && ! (bool) config('services.telegram.allow_during_tests')) {
            return false;
        }

        return true;
    }

    private function registrationMessage(User $company): string
    {
        return implode("\n", [
            '🆕 <b>Nuova registrazione</b>',
            '🏢 <b>Società:</b> '.$this->escape($company->name),
            '👤 <b>Responsabile:</b> '.$this->escape($company->responsible_name ?: 'Non indicato'),
            '📞 <b>Telefono:</b> '.$this->escape($company->responsible_phone ?: 'Non indicato'),
            '📧 <b>Email:</b> '.$this->escape($company->email),
            '⏳ <b>Stato:</b> in attesa di approvazione',
        ]);
    }

    private function approvalMessage(User $company): string
    {
        return implode("\n", [
            '✅ <b>Account approvato</b>',
            '🏢 <b>Società:</b> '.$this->escape($company->name),
            '👤 <b>Responsabile:</b> '.$this->escape($company->responsible_name ?: 'Non indicato'),
            '📞 <b>Telefono:</b> '.$this->escape($company->responsible_phone ?: 'Non indicato'),
            '📧 <b>Email:</b> '.$this->escape($company->email),
            '📌 <b>Stato:</b> approvato dall admin',
        ]);
    }

    private function documentMessage(UploadedDocument $document, string $event): string
    {
        $title = match ($event) {
            'replacement' => '♻️ <b>Sostituzione documento</b>',
            'expired_update' => '⏰ <b>Aggiornamento documento scaduto</b>',
            default => '📄 <b>Nuovo documento caricato</b>',
        };

        return implode("\n", [
            $title,
            '🏢 <b>Società:</b> '.$this->escape($document->companyUser()?->name ?: 'Non disponibile'),
            '📂 <b>Sezione:</b> '.$this->escape($this->sectionLabel($document)),
            '📌 <b>Documento:</b> '.$this->escape($this->documentLabel($document)),
            '👤 <b>Elemento:</b> '.$this->escape($this->ownerLabel($document)),
            '⏳ <b>Stato:</b> in attesa di approvazione',
        ]);
    }

    private function documentIntegrationMessage(UploadedDocument $document): string
    {
        return implode("\n", [
            '🧩 <b>Nuova integrazione caricata</b>',
            '🏢 <b>Società:</b> '.$this->escape($document->companyUser()?->name ?: 'Non disponibile'),
            '📂 <b>Sezione:</b> '.$this->escape($this->sectionLabel($document)),
            '📌 <b>Documento padre:</b> '.$this->escape($document->template?->name ?: 'Documento'),
            '📝 <b>Integrazione:</b> '.$this->escape($document->integration_name ?: 'Integrazione'),
            '👤 <b>Elemento:</b> '.$this->escape($this->ownerLabel($document)),
            '⏳ <b>Stato:</b> in attesa di approvazione',
        ]);
    }

    private function sectionLabel(UploadedDocument $document): string
    {
        return $document->template?->section?->name ?: 'Documento';
    }

    private function documentLabel(UploadedDocument $document): string
    {
        return trim(implode(' / ', array_filter([
            $document->template?->name,
            $document->subtemplate?->name,
        ]))) ?: 'Documento';
    }

    private function ownerLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => 'Società',
            $documentable instanceof Employee => 'Dipendente: '.trim($documentable->first_name.' '.$documentable->last_name),
            $documentable instanceof Vehicle => 'Veicolo: '.$documentable->plate.' ('.$documentable->capacity.' posti)',
            default => 'Elemento non disponibile',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
