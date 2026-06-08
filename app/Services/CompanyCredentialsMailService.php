<?php

namespace App\Services;

use App\Mail\CompanyCredentialsMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class CompanyCredentialsMailService
{
    /**
     * @return array{total:int,sent:int,skipped:int,matched:int,unmatched:int}
     */
    public function sendManual(array $selectedEmails = []): array
    {
        $entries = $this->entries();
        $selectedEmails = collect($selectedEmails)
            ->map(fn ($email): string => mb_strtolower(trim((string) $email)))
            ->filter()
            ->values()
            ->all();
        $sent = 0;
        $skipped = 0;
        $matched = 0;
        $sentEmails = [];
        $actor = auth('admin')->user();

        foreach ($entries as $entry) {
            $email = mb_strtolower(trim((string) ($entry['email'] ?? '')));
            $password = trim((string) ($entry['password'] ?? ''));

            if ($email === '' || $password === '') {
                $skipped++;
                continue;
            }

            if (($selectedEmails !== []) && (! in_array($email, $selectedEmails, true))) {
                continue;
            }

            if (isset($sentEmails[$email])) {
                $skipped++;
                continue;
            }

            $company = User::query()
                ->where('role', 'company')
                ->whereRaw('lower(email) = ?', [$email])
                ->first();

            $recipientName = trim((string) ($entry['company_name'] ?? ''));
            $recipientName = $recipientName !== '' ? $recipientName : ($company?->name ?: $email);

            Mail::to($email)->send(new CompanyCredentialsMail(
                $recipientName,
                $email,
                $password,
                url('/login.html'),
            ));

            $sentEmails[$email] = true;
            $sent++;

            if ($company) {
                $matched++;

                AuditLog::record(
                    'company.credentials_emailed',
                    $company,
                    'Credenziali inviate manualmente',
                    [
                        'email' => $email,
                        'source' => $this->configuredPath(),
                    ],
                    $actor,
                    $company,
                );
            }
        }

        return [
            'total' => count($entries),
            'sent' => $sent,
            'skipped' => $skipped,
            'matched' => $matched,
            'unmatched' => max(0, $sent - $matched),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function selectableOptions(): array
    {
        return collect($this->entries())
            ->map(function (array $entry): ?array {
                $email = mb_strtolower(trim((string) ($entry['email'] ?? '')));
                $password = trim((string) ($entry['password'] ?? ''));

                if ($email === '' || $password === '') {
                    return null;
                }

                $company = User::query()
                    ->where('role', 'company')
                    ->whereRaw('lower(email) = ?', [$email])
                    ->first();

                $label = $company
                    ? $company->name.' - '.$email
                    : $email.' - non collegata al database';

                return [$email => $label];
            })
            ->filter()
            ->collapse()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entries(): array
    {
        $path = $this->configuredPath();

        if (! File::exists($path)) {
            throw new RuntimeException('File credenziali non trovato: '.$path);
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Il file credenziali non contiene un JSON valido.');
        }

        return array_values(array_filter($decoded, fn ($entry): bool => is_array($entry)));
    }

    public function configuredPath(): string
    {
        return (string) config('services.companies.credentials_json_path');
    }
}
