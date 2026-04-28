<?php

namespace App\Filament\Widgets;

use App\Models\UploadedDocument;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DocumentStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Panoramica documentale';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $expiring = UploadedDocument::query()
            ->where('status', 'approved')
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '<=', now()->addDays(30));
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('internal_expiry_date')
                            ->whereDate('internal_expiry_date', '<=', now()->addDays(30));
                    });
            })
            ->count();

        return [
            Stat::make('Societa registrate', User::query()->where('role', 'company')->count())
                ->description('Aziende abilitate al portale')
                ->color('gray')
                ->icon(Heroicon::OutlinedBuildingOffice2),
            Stat::make('In attesa', UploadedDocument::query()->where('status', 'pending')->count())
                ->description('Documenti da verificare')
                ->color('warning')
                ->icon(Heroicon::OutlinedClock),
            Stat::make('Respinti', UploadedDocument::query()->where('status', 'rejected')->count())
                ->description('Richiedono un nuovo caricamento')
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle),
            Stat::make('Scadenza 30 giorni', $expiring)
                ->description('Approvati con scadenza vicina')
                ->color($expiring > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedDocumentText),
        ];
    }
}
