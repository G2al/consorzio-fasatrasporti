<?php

namespace App\Console\Commands;

use App\Models\DocumentDeadlineNotification;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyDocumentDeadlines extends Command
{
    protected $signature = 'documents:notify-deadlines {--dry-run : Mostra le scadenze senza inviare Telegram}';

    protected $description = 'Invia su Telegram gli avvisi per le scadenze dei documenti.';

    public function handle(): int
    {
        $items = $this->pendingDeadlineItems();

        if ($items->isEmpty()) {
            $this->info('Nessuna scadenza da notificare.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $items->each(fn (array $item) => $this->line($this->itemLine($item)));

            return self::SUCCESS;
        }

        if (! $this->sendTelegramMessage($this->telegramMessage($items))) {
            return self::FAILURE;
        }

        $now = now();
        $items->each(function (array $item) use ($now): void {
            DocumentDeadlineNotification::query()->firstOrCreate(
                [
                    'uploaded_document_id' => $item['document']->id,
                    'channel' => 'telegram',
                    'deadline_type' => $item['deadline_type'],
                    'bucket' => $item['bucket'],
                    'deadline_date' => $item['deadline_date']->toDateString(),
                ],
                [
                    'sent_at' => $now,
                ],
            );
        });

        $this->info('Notifica Telegram inviata per '.$items->count().' scadenze.');

        return self::SUCCESS;
    }

    private function pendingDeadlineItems(): Collection
    {
        $thresholds = $this->thresholds();
        $maxDays = max($thresholds);
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($maxDays)->endOfDay();

        return UploadedDocument::query()
            ->where('status', 'approved')
            ->where(function ($query) use ($limit): void {
                $query
                    ->whereDate('expiry_date', '<=', $limit)
                    ->orWhereDate('internal_expiry_date', '<=', $limit);
            })
            ->with([
                'template.section',
                'documentable' => fn ($morphTo) => $morphTo->morphWith([
                    Employee::class => ['user'],
                    Vehicle::class => ['user'],
                ]),
            ])
            ->get()
            ->flatMap(fn (UploadedDocument $document): array => $this->deadlineItemsForDocument($document, $thresholds, $today))
            ->reject(fn (array $item): bool => $this->alreadySent($item))
            ->sortBy(fn (array $item): string => $item['sort'].'-'.$item['deadline_date']->toDateString().'-'.$item['company'])
            ->values();
    }

    private function deadlineItemsForDocument(UploadedDocument $document, array $thresholds, Carbon $today): array
    {
        $items = [];

        if ($document->expiry_date) {
            $items[] = $this->deadlineItem($document, 'document', 'Scadenza documento', $document->expiry_date, $thresholds, $today);
        }

        if ($document->internal_expiry_date) {
            $items[] = $this->deadlineItem(
                $document,
                'internal',
                $document->internal_expiry_name ?: 'Requisito interno',
                $document->internal_expiry_date,
                $thresholds,
                $today,
            );
        }

        return array_values(array_filter($items));
    }

    private function deadlineItem(UploadedDocument $document, string $type, string $label, Carbon $date, array $thresholds, Carbon $today): ?array
    {
        $days = (int) $today->diffInDays($date->copy()->startOfDay(), false);
        $bucket = $this->bucketForDays($days, $thresholds);

        if ($bucket === null) {
            return null;
        }

        return [
            'document' => $document,
            'deadline_type' => $type,
            'label' => $label,
            'bucket' => $bucket,
            'deadline_date' => $date->copy()->startOfDay(),
            'days' => $days,
            'company' => $this->companyLabel($document),
            'owner' => $this->ownerLabel($document),
            'document_name' => $document->template->name,
            'sort' => match ($bucket) {
                'expired' => '0',
                '1' => '1',
                '15' => '2',
                default => '3',
            },
        ];
    }

    private function bucketForDays(int $days, array $thresholds): ?string
    {
        if ($days < 0) {
            return 'expired';
        }

        foreach ($thresholds as $threshold) {
            if ($days <= $threshold) {
                return (string) $threshold;
            }
        }

        return null;
    }

    private function alreadySent(array $item): bool
    {
        return DocumentDeadlineNotification::query()
            ->where('uploaded_document_id', $item['document']->id)
            ->where('channel', 'telegram')
            ->where('deadline_type', $item['deadline_type'])
            ->where('bucket', $item['bucket'])
            ->whereDate('deadline_date', $item['deadline_date'])
            ->exists();
    }

    private function telegramMessage(Collection $items): string
    {
        $groups = [
            'expired' => 'Scaduti',
            '1' => 'Entro 1 giorno',
            '15' => 'Entro 15 giorni',
            '30' => 'Entro 30 giorni',
        ];

        $lines = [
            '⏰ <b>Scadenze documenti</b>',
            '<i>Promemoria automatico documenti approvati</i>',
        ];

        foreach ($groups as $bucket => $title) {
            $bucketItems = $items->where('bucket', $bucket);

            if ($bucketItems->isEmpty()) {
                continue;
            }

            $lines[] = '';
            $lines[] = $this->bucketIcon($bucket).' <b>'.$title.'</b>';

            $bucketItems->take(25)->each(function (array $item) use (&$lines): void {
                $lines[] = $this->itemLine($item);
            });

            if ($bucketItems->count() > 25) {
                $lines[] = '...altre '.($bucketItems->count() - 25).' scadenze';
            }
        }

        return implode("\n", $lines);
    }

    private function itemLine(array $item): string
    {
        $date = $item['deadline_date']->format('d/m/Y');
        $days = (int) $item['days'];
        $timeLabel = $days < 0
            ? 'Scaduto il '.$date
            : 'Scade il '.$date.' - '.$days.' giorni';

        return implode("\n", [
            '• <b>'.$this->escape($item['company']).'</b>',
            '  📄 '.$this->escape($item['document_name']),
            '  👤 '.$this->escape($item['owner']),
            '  📌 '.$this->escape($item['label']),
            '  📅 '.$this->escape($timeLabel),
        ]);
    }

    private function bucketIcon(string $bucket): string
    {
        return match ($bucket) {
            'expired' => '🔴',
            '1' => '🚨',
            '15' => '🟠',
            default => '🟡',
        };
    }

    private function sendTelegramMessage(string $message): bool
    {
        if (! (bool) config('services.telegram.expiry_enabled')) {
            $this->warn('Telegram scadenze disattivato.');

            return false;
        }

        $token = (string) config('services.telegram.expiry_bot_token');
        $chatId = (string) config('services.telegram.expiry_chat_id');

        if ($token === '' || $chatId === '') {
            $this->warn('Token o chat ID Telegram scadenze mancanti.');

            return false;
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

            return true;
        } catch (Throwable $exception) {
            Log::warning('Invio Telegram scadenze non riuscito.', [
                'message' => $exception->getMessage(),
            ]);

            $this->error('Invio Telegram fallito: '.$exception->getMessage());

            return false;
        }
    }

    private function thresholds(): array
    {
        $value = (string) config('services.documents.deadline_reminder_days', '30,15');

        $thresholds = collect(explode(',', $value))
            ->map(fn (string $day): int => (int) trim($day))
            ->filter(fn (int $day): bool => $day > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $thresholds !== [] ? $thresholds : [15, 30];
    }

    private function companyLabel(UploadedDocument $document): string
    {
        return $document->companyUser()?->name ?? 'Societa non disponibile';
    }

    private function ownerLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => 'Societa',
            $documentable instanceof Employee => 'Dipendente: '.trim($documentable->first_name.' '.$documentable->last_name),
            $documentable instanceof Vehicle => 'Veicolo: '.$documentable->plate.' ('.$documentable->capacity.' posti)',
            default => 'Elemento eliminato',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
