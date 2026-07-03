<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MissingDocumentsReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMissingDocumentsEmails extends Command
{
    protected $signature = 'documents:send-missing-emails {--dry-run : Mostra le societa coinvolte senza inviare email}';

    protected $description = 'Invia automaticamente i riepiloghi dei documenti mancanti a tutte le societa.';

    public function handle(MissingDocumentsReportService $service): int
    {
        $companies = User::query()
            ->where('role', 'company')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('name')
            ->get();

        $totals = [
            'companies' => $companies->count(),
            'sent_companies' => 0,
            'sent_sections' => 0,
            'empty_companies' => 0,
            'failed_companies' => 0,
        ];

        foreach ($companies as $company) {
            try {
                if ($this->option('dry-run')) {
                    $reports = $service->reports($company);
                    $sections = collect($reports)
                        ->map(fn (array $report): int => count($report['items']))
                        ->filter(fn (int $count): bool => $count > 0);

                    if ($sections->isEmpty()) {
                        $totals['empty_companies']++;
                        $this->line('[SKIP] '.$company->name.' - nessun mancante');
                        continue;
                    }

                    $totals['sent_companies']++;
                    $totals['sent_sections'] += $sections->count();

                    $this->line('[DRY] '.$company->name.' <'.$company->email.'> - sezioni: '.$sections->count().' - documenti: '.$sections->sum());
                    continue;
                }

                $sent = $service->sendManual($company);
                $sectionCount = collect($sent)->filter(fn (int $count): bool => $count > 0)->count();

                if ($sectionCount === 0) {
                    $totals['empty_companies']++;
                    continue;
                }

                $totals['sent_companies']++;
                $totals['sent_sections'] += $sectionCount;
            } catch (Throwable $exception) {
                $totals['failed_companies']++;

                Log::error('Invio automatico email mancanti non riuscito.', [
                    'company_id' => $company->id,
                    'company_email' => $company->email,
                    'message' => $exception->getMessage(),
                ]);

                $this->warn($company->name.': '.$exception->getMessage());
            }
        }

        $this->info(sprintf(
            'Societa: %d | con invii: %d | sezioni inviate: %d | senza mancanti: %d | errori: %d',
            $totals['companies'],
            $totals['sent_companies'],
            $totals['sent_sections'],
            $totals['empty_companies'],
            $totals['failed_companies'],
        ));

        return $totals['failed_companies'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
