<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\DocumentExemption;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Resources\Concerns\HasTabs;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class TemplateCompanies extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithRecord;
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    protected static string $resource = DocumentTemplateResource::class;

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->loadDefaultActiveTab();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Societa per '.$this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Societa';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadApproved')
                ->label('ZIP approvati')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.templates.show', $this->record))
                ->openUrlInNewTab(),
            Action::make('downloadApprovedPdf')
                ->label('PDF approvati')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => route('admin.downloads.templates.pdf', $this->record))
                ->openUrlInNewTab(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tutte'),
            'with_document' => Tab::make('Con documento')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDocumentPresenceFilter($query, true)),
            'missing' => Tab::make('Mancanti')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDocumentPresenceFilter($query, false)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getTableQuery())
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->columns([
                TextColumn::make('name')
                    ->label('Societa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('responsible_name')
                    ->label('Responsabile')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('template_document_status')
                    ->label('Stato documento')
                    ->badge()
                    ->state(fn (User $record): ?string => $record->template_document_status ?? ($record->template_exemption_status ? 'exemption_'.$record->template_exemption_status : null))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                        'pending' => 'In attesa',
                        'exemption_approved' => 'Esente approvata',
                        'exemption_pending' => 'Esenzione in attesa',
                        'exemption_rejected' => 'Esenzione rifiutata',
                        default => 'Mancante',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        'exemption_approved' => 'success',
                        'exemption_pending' => 'warning',
                        'exemption_rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('template_document_expiry_date')
                    ->label('Scadenza')
                    ->date('d/m/Y')
                    ->placeholder('-'),
                TextColumn::make('template_document_internal_expiry_date')
                    ->label('Scad. requisito')
                    ->date('d/m/Y')
                    ->description(fn (User $record): ?string => $record->template_document_internal_expiry_name)
                    ->placeholder('-'),
                TextColumn::make('template_document_updated_at')
                    ->label('Aggiornato')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('downloadDocument')
                    ->label('Scarica')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (User $record): bool => filled($record->template_document_id))
                    ->url(fn (User $record): string => route('admin.downloads.documents.show', $record->template_document_id))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('name');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }

    protected function getTableQuery(): Builder
    {
        return User::query()
            ->where('role', 'company')
            ->addSelect([
                'template_document_id' => $this->templateDocumentSubquery('id'),
                'template_document_status' => $this->templateDocumentSubquery('status'),
                'template_document_expiry_date' => $this->templateDocumentSubquery('expiry_date'),
                'template_document_internal_expiry_name' => $this->templateDocumentSubquery('internal_expiry_name'),
                'template_document_internal_expiry_date' => $this->templateDocumentSubquery('internal_expiry_date'),
                'template_document_updated_at' => $this->templateDocumentSubquery('updated_at'),
                'template_exemption_status' => $this->templateExemptionSubquery('status'),
            ]);
    }

    private function applyDocumentPresenceFilter(Builder $query, bool $present): Builder
    {
        return $present
            ? $query->whereExists($this->templateDocumentExistsSubquery())
            : $query->whereNotExists($this->templateDocumentExistsSubquery());
    }

    private function templateDocumentSubquery(string $column): Builder
    {
        return UploadedDocument::query()
            ->select($column)
            ->where('template_id', $this->record->id)
            ->where($this->templateDocumentOwnerConstraint(...))
            ->latest('updated_at')
            ->limit(1);
    }

    private function templateDocumentExistsSubquery(): Builder
    {
        return UploadedDocument::query()
            ->selectRaw('1')
            ->where('template_id', $this->record->id)
            ->where($this->templateDocumentOwnerConstraint(...))
            ->limit(1);
    }

    private function templateExemptionSubquery(string $column): Builder
    {
        return DocumentExemption::query()
            ->select($column)
            ->where('template_id', $this->record->id)
            ->where($this->templateExemptionOwnerConstraint(...))
            ->latest('updated_at')
            ->limit(1);
    }

    private function templateDocumentOwnerConstraint(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->where('documentable_type', User::class)
                    ->whereColumn('documentable_id', 'users.id');
            })
            ->orWhere(function (Builder $query): void {
                $query
                    ->where('documentable_type', Employee::class)
                    ->whereExists(Employee::query()
                        ->selectRaw('1')
                        ->whereColumn('employees.id', 'uploaded_documents.documentable_id')
                        ->whereColumn('employees.user_id', 'users.id'));
            })
            ->orWhere(function (Builder $query): void {
                $query
                    ->where('documentable_type', Vehicle::class)
                    ->whereExists(Vehicle::query()
                        ->selectRaw('1')
                        ->whereColumn('vehicles.id', 'uploaded_documents.documentable_id')
                        ->whereColumn('vehicles.user_id', 'users.id'));
            });
    }

    private function templateExemptionOwnerConstraint(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->where('exemptable_type', User::class)
                    ->whereColumn('exemptable_id', 'users.id');
            })
            ->orWhere(function (Builder $query): void {
                $query
                    ->where('exemptable_type', Employee::class)
                    ->whereExists(Employee::query()
                        ->selectRaw('1')
                        ->whereColumn('employees.id', 'document_exemptions.exemptable_id')
                        ->whereColumn('employees.user_id', 'users.id'));
            })
            ->orWhere(function (Builder $query): void {
                $query
                    ->where('exemptable_type', Vehicle::class)
                    ->whereExists(Vehicle::query()
                        ->selectRaw('1')
                        ->whereColumn('vehicles.id', 'document_exemptions.exemptable_id')
                        ->whereColumn('vehicles.user_id', 'users.id'));
            });
    }
}
