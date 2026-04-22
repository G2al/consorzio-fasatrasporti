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
                    'text' => $this->registrationMessage($company),
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Invio notifica Telegram registrazione societa non riuscito.', [
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
        $adminUrl = rtrim((string) config('app.url'), '/').'/admin';

        return implode("\n", [
            '🟢 <b>Nuova società registrata</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '',
            '🏢 <b>Società</b>',
            $this->escape($company->name),
            '',
            '👤 <b>Responsabile</b>',
            $this->escape($company->responsible_name ?: 'Non indicato'),
            '',
            '🧾 <b>Partita IVA</b>',
            $this->escape($company->vat_number ?: 'Non indicata'),
            '',
            '✉️ <b>Email</b>',
            $this->escape($company->email),
            '',
            '🕒 <b>Registrata il</b>',
            $company->created_at?->format('d/m/Y H:i'),
            '',
            '🔗 <a href="'.$this->escape($adminUrl).'">Apri pannello admin</a>',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
