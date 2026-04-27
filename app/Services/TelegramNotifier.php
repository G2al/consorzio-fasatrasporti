<?php

namespace App\Services;

use App\Models\User;
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
