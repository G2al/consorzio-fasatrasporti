<?php

namespace App\Services;

use App\Mail\DeadlineReminderMail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class DeadlineReminderMailService
{
    public function send(User $company, Collection $items): void
    {
        $recipient = mb_strtolower(trim((string) $company->email));

        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('La societa non ha un indirizzo email valido.');
        }

        if ($items->isEmpty()) {
            return;
        }

        Mail::to($recipient)->send(new DeadlineReminderMail(
            $company,
            $this->groupedItems($items),
        ));
    }

    private function groupedItems(Collection $items): array
    {
        $titles = [
            '1' => 'Entro 1 giorno',
            '15' => 'Entro 15 giorni',
            '30' => 'Entro 30 giorni',
        ];

        return collect($titles)
            ->map(function (string $title, string $bucket) use ($items): array {
                return [
                    'bucket' => $bucket,
                    'title' => $title,
                    'items' => $items
                        ->where('bucket', $bucket)
                        ->sortBy(fn (array $item): string => $item['deadline_date']->toDateString().'-'.$item['document_name'].'-'.$item['owner'])
                        ->map(function (array $item): array {
                            return [
                                'owner' => $item['owner'],
                                'document' => $item['document_name'],
                                'label' => $item['label'],
                                'date' => $item['deadline_date']->format('d/m/Y'),
                                'days' => $item['days'],
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }
}
