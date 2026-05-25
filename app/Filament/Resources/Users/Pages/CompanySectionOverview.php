<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\CompanyDocumentOverviewReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class CompanySectionOverview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.pages.company-section-overview';

    #[Url(as: 'filter')]
    public string $filter = 'all';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->record->role === 'company', 404);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Panoramica totale';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Panoramica totale';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editCompany')
                ->label('Torna alla societa')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('edit', ['record' => $this->record])),
            Action::make('documentOverview')
                ->label('Panoramica documenti')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('documents', ['record' => $this->record])),
            Action::make('downloadPdf')
                ->label('PDF riepilogo')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.companies.company-overview.pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('downloadFilteredPdf')
                ->label(fn (): string => 'PDF '.$this->report()->filterLabel($this->filter))
                ->icon(Heroicon::OutlinedFunnel)
                ->color('primary')
                ->visible(fn (): bool => $this->filter !== 'all')
                ->url(fn (): string => route('admin.downloads.companies.company-overview.pdf', $this->record).'?filter='.$this->filter)
                ->openUrlInNewTab(),
        ];
    }

    public function summary(): array
    {
        return $this->report()->companySectionSummary($this->record);
    }

    public function matrix(): array
    {
        return $this->report()->companySectionMatrix($this->record, $this->filter);
    }

    public function filterUrl(string $filter): string
    {
        return UserResource::getUrl('companyOverview', [
            'record' => $this->record,
            'filter' => $filter,
        ]);
    }

    private function report(): CompanyDocumentOverviewReport
    {
        return app(CompanyDocumentOverviewReport::class);
    }
}
