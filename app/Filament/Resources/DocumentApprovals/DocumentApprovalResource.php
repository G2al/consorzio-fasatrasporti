<?php

namespace App\Filament\Resources\DocumentApprovals;

use App\Filament\Resources\DocumentApprovals\Pages\ManageDocumentApprovals;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class DocumentApprovalResource extends Resource
{
    protected static ?string $model = UploadedDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = 'Approvazione documenti';

    protected static string|\UnitEnum|null $navigationGroup = 'Documenti';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'documento caricato';

    protected static ?string $pluralModelLabel = 'documenti caricati';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                    ])
                    ->required(),
                DatePicker::make('expiry_date')
                    ->label('Scadenza')
                    ->helperText('Opzionale. La societa la vede solo come informazione.'),
                DateTimePicker::make('approved_at')
                    ->label('Data approvazione')
                    ->helperText('Compilata automaticamente dal pulsante Approva.'),
                Textarea::make('admin_notes')
                    ->label('Note admin')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('template.section.name')
                    ->label('Sezione')
                    ->badge(),
                TextColumn::make('template.name')
                    ->label('Documento')
                    ->searchable(),
                TextColumn::make('documentable.name')
                    ->label('Società / elemento')
                    ->formatStateUsing(fn (UploadedDocument $record): string => static::documentableLabel($record)),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                        default => 'In attesa',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('expiry_date')
                    ->label('Scadenza')
                    ->date('d/m/Y'),
                TextColumn::make('approved_at')
                    ->label('Approvato il')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('created_at')
                    ->label('Caricato')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Societa')
                    ->options(fn (): array => User::query()
                        ->where('role', 'company')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $companyId = $data['value'] ?? null;

                        if (blank($companyId)) {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($companyId): void {
                            $query
                                ->where(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', User::class)
                                        ->where('documentable_id', $companyId);
                                })
                                ->orWhere(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', Employee::class)
                                        ->whereIn('documentable_id', Employee::query()
                                            ->select('id')
                                            ->where('user_id', $companyId));
                                })
                                ->orWhere(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', Vehicle::class)
                                        ->whereIn('documentable_id', Vehicle::query()
                                            ->select('id')
                                            ->where('user_id', $companyId));
                                });
                        });
                    }),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                    ]),
                Filter::make('created_at')
                    ->label('Data caricamento')
                    ->schema([
                        DatePicker::make('uploaded_from')
                            ->label('Caricato dal'),
                        DatePicker::make('uploaded_until')
                            ->label('Caricato al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['uploaded_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['uploaded_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                Filter::make('expiry_date')
                    ->label('Scadenza')
                    ->schema([
                        DatePicker::make('expires_from')
                            ->label('Scade dal'),
                        DatePicker::make('expires_until')
                            ->label('Scade al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['expires_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expiry_date', '>=', $date))
                        ->when($data['expires_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expiry_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('open_file')
                    ->label('Apri')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (UploadedDocument $record): string => $record->file_url)
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->label('Modifica'),
                Action::make('history')
                    ->label('Storico')
                    ->icon(Heroicon::OutlinedClock)
                    ->modalHeading('Storico documento')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalContent(fn (UploadedDocument $record): HtmlString => static::historyContent($record)),
                Action::make('approve')
                    ->label('Approva')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->form([
                        DatePicker::make('expiry_date')
                            ->label('Scadenza')
                            ->helperText('Opzionale. Lascia vuoto se il documento non scade.'),
                    ])
                    ->fillForm(fn (UploadedDocument $record): array => [
                        'expiry_date' => $record->expiry_date,
                    ])
                    ->action(function (UploadedDocument $record, array $data): void {
                        $record->update([
                            'status' => 'approved',
                            'expiry_date' => $data['expiry_date'] ?? null,
                            'approved_at' => now(),
                            'admin_notes' => null,
                        ]);

                        AuditLog::record('document.approved', $record->fresh(['template', 'documentable']), 'Documento approvato', [
                            'template' => $record->template->name,
                            'expiry_date' => $data['expiry_date'] ?? null,
                        ]);
                    }),
                Action::make('reject')
                    ->label('Respingi')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedArchiveBoxXMark)
                    ->form([
                        Textarea::make('admin_notes')
                            ->label('Note di rifiuto')
                            ->required(),
                    ])
                    ->action(function (UploadedDocument $record, array $data): void {
                        $record->update([
                            'status' => 'rejected',
                            'approved_at' => null,
                            'admin_notes' => $data['admin_notes'],
                        ]);

                        AuditLog::record('document.rejected', $record->fresh(['template', 'documentable']), 'Documento respinto', [
                            'template' => $record->template->name,
                            'notes' => $data['admin_notes'],
                        ]);
                    }),
            ]);
    }

    protected static function documentableLabel(UploadedDocument $record): string
    {
        $documentable = $record->documentable;

        return match (true) {
            $documentable instanceof \App\Models\User => $documentable->name,
            $documentable instanceof \App\Models\Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof \App\Models\Vehicle => "{$documentable->brand_model} ({$documentable->plate})",
            default => 'Elemento eliminato',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentApprovals::route('/'),
        ];
    }

    protected static function historyContent(UploadedDocument $record): HtmlString
    {
        $record->loadMissing(['template.section', 'versions' => fn ($query) => $query->latest(), 'documentable']);
        $logs = AuditLog::query()
            ->where('auditable_type', UploadedDocument::class)
            ->where('auditable_id', $record->id)
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();

        $versions = $record->versions->map(function ($version): string {
            $date = $version->versioned_at?->format('d/m/Y H:i') ?? $version->created_at?->format('d/m/Y H:i') ?? '-';
            $status = match ($version->status) {
                'approved' => 'Approvato',
                'rejected' => 'Respinto',
                default => 'In attesa',
            };

            return '<li><a href="'.e($version->file_url).'" target="_blank">Apri versione</a><span>'.e($status).' - '.e($date).'</span></li>';
        })->join('');

        $logRows = $logs->map(fn (AuditLog $log): string => '<li><strong>'.e($log->description).'</strong><span>'.e($log->user?->name ?? 'Sistema').' - '.e($log->created_at->format('d/m/Y H:i')).'</span></li>')->join('');

        return new HtmlString('
            <div class="space-y-5">
                <div>
                    <h3 class="text-sm font-semibold">Documento corrente</h3>
                    <p class="text-sm text-gray-600">'.e($record->template->name).' - '.e(static::documentableLabel($record)).'</p>
                    <p class="text-sm"><a href="'.e($record->file_url).'" target="_blank">Apri file corrente</a></p>
                </div>
                <div>
                    <h3 class="text-sm font-semibold">Versioni precedenti</h3>
                    <ul class="mt-2 space-y-2">'.($versions ?: '<li class="text-sm text-gray-600">Nessuna versione precedente.</li>').'</ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold">Attivita collegate</h3>
                    <ul class="mt-2 space-y-2">'.($logRows ?: '<li class="text-sm text-gray-600">Nessuna attivita registrata.</li>').'</ul>
                </div>
            </div>
        ');
    }
}
