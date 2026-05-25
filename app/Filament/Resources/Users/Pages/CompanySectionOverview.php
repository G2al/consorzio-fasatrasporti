<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\CompanyDocumentOverviewReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class CompanySectionOverview extends Page
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.pages.company-section-overview';

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'search')]
    public string $search = '';

    public function getTitle(): string|Htmlable
    {
        return 'Panoramica totale';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Sezione societa di tutte le aziende';
    }

    public function getBreadcrumb(): string
    {
        return 'Panoramica totale';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('index')
                ->label('Torna alle societa')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('index')),
            Action::make('downloadPdf')
                ->label('PDF riepilogo')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.companies.company-overview.pdf'))
                ->openUrlInNewTab(),
            Action::make('downloadFilteredPdf')
                ->label(fn (): string => 'PDF '.$this->report()->filterLabel($this->filter))
                ->icon(Heroicon::OutlinedFunnel)
                ->color('primary')
                ->visible(fn (): bool => $this->filter !== 'all')
                ->url(fn (): string => route('admin.downloads.companies.company-overview.pdf').'?filter='.$this->filter)
                ->openUrlInNewTab(),
        ];
    }

    public function summary(): array
    {
        return $this->report()->globalCompanySectionSummary();
    }

    public function matrix(): array
    {
        return $this->report()->globalCompanySectionMatrix($this->filter, $this->search);
    }

    public function filterUrl(string $filter): string
    {
        return UserResource::getUrl('companyOverview', [
            'filter' => $filter,
            'search' => $this->search !== '' ? $this->search : null,
        ]);
    }

    private function report(): CompanyDocumentOverviewReport
    {
        return app(CompanyDocumentOverviewReport::class);
    }
}
