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

class DocumentOverview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.pages.document-overview';

    #[Url(as: 'filter')]
    public string $filter = 'all';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->record->role === 'company', 404);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Panoramica documenti';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Panoramica documenti';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editCompany')
                ->label('Torna alla societa')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('edit', ['record' => $this->record])),
            Action::make('downloadAll')
                ->label('ZIP documenti')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'all']))
                ->openUrlInNewTab(),
            Action::make('downloadPdf')
                ->label('PDF riepilogo')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.companies.pdf', [$this->record, 'all']))
                ->openUrlInNewTab(),
            Action::make('downloadFilteredPdf')
                ->label(fn (): string => 'PDF '.$this->report()->filterLabel($this->filter))
                ->icon(Heroicon::OutlinedFunnel)
                ->color('primary')
                ->visible(fn (): bool => $this->filter !== 'all')
                ->url(fn (): string => route('admin.downloads.companies.pdf', [$this->record, 'all']).'?filter='.$this->filter)
                ->openUrlInNewTab(),
        ];
    }

    public function groups(bool $filtered = true): array
    {
        return $filtered
            ? $this->report()->groups($this->record, $this->filter)
            : $this->report()->groupsUnfiltered($this->record);
    }

    public function summary(): array
    {
        return $this->report()->summary($this->record);
    }

    public function filterUrl(string $filter): string
    {
        return UserResource::getUrl('documents', [
            'record' => $this->record,
            'filter' => $filter,
        ]);
    }

    private function report(): CompanyDocumentOverviewReport
    {
        return app(CompanyDocumentOverviewReport::class);
    }
}
