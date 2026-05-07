<?php

namespace App\Filament\Resources\EntityDeletionRequests;

use App\Filament\Resources\EntityDeletionRequests\Pages\ManageEntityDeletionRequests;
use App\Models\Employee;
use App\Models\EntityDeletionRequest;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityDeletionRequestResource extends Resource
{
    protected static ?string $model = EntityDeletionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedXCircle;

    protected static ?string $navigationLabel = 'Richieste eliminazione';

    protected static string|\UnitEnum|null $navigationGroup = 'Richieste';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'richiesta eliminazione';

    protected static ?string $pluralModelLabel = 'richieste eliminazione';

    public static function canAccess(): bool
    {
        return in_array(auth('admin')->user()?->role, ['admin', 'reviewer'], true);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EntityDeletionRequest::query()
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Richieste di eliminazione in attesa';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'company',
                'deletable' => fn (MorphTo $morphTo): MorphTo => $morphTo->morphWith([
                    Employee::class => ['user'],
                    Vehicle::class => ['user'],
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->state(fn (EntityDeletionRequest $record): string => $record->typeLabel())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('company_display')
                    ->label('Societa / elemento')
                    ->state(fn (EntityDeletionRequest $record): string => $record->company?->name ?? 'Societa non disponibile')
                    ->description(fn (EntityDeletionRequest $record): string => $record->reviewLabel().($record->snapshot_secondary ? ' - '.$record->snapshot_secondary : '')),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Approvata',
                        'rejected' => 'Respinta',
                        default => 'In attesa',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('requested_reason')
                    ->label('Note societa')
                    ->wrap()
                    ->limit(90)
                    ->placeholder('-'),
                TextColumn::make('admin_notes')
                    ->label('Note admin')
                    ->wrap()
                    ->limit(90)
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Richiesta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('Revisionata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Societa')
                    ->options(fn (): array => User::query()
                        ->where('role', 'company')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvate',
                        'rejected' => 'Respinte',
                    ]),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Revisiona')
                    ->tooltip('Revisiona richiesta')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('primary')
                    ->modalHeading(fn (EntityDeletionRequest $record): string => 'Revisione: '.$record->reviewLabel())
                    ->modalDescription(fn (EntityDeletionRequest $record): string => $record->company?->name ?? 'Societa non disponibile')
                    ->modalSubmitActionLabel('Salva decisione')
                    ->modalCancelActionLabel('Chiudi senza modifiche')
                    ->form([
                        Section::make('Dati richiesta')
                            ->schema([
                                TextInput::make('review_type')
                                    ->label('Tipo')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_company')
                                    ->label('Societa')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_subject')
                                    ->label('Elemento')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_status')
                                    ->label('Stato attuale')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_requested_at')
                                    ->label('Richiesta il')
                                    ->disabled()
                                    ->dehydrated(false),
                                Textarea::make('requested_reason')
                                    ->label('Note societa')
                                    ->rows(4)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Decisione')
                            ->schema([
                                Radio::make('decision')
                                    ->label('Esito revisione')
                                    ->options([
                                        'approve' => 'Approva eliminazione',
                                        'reject' => 'Respingi richiesta',
                                    ])
                                    ->default('approve')
                                    ->inline()
                                    ->live()
                                    ->required()
                                    ->columnSpanFull(),
                                Textarea::make('admin_notes')
                                    ->label('Note admin')
                                    ->rows(4)
                                    ->helperText('Obbligatorie se respingi la richiesta.')
                                    ->required(fn (Get $get): bool => $get('decision') === 'reject')
                                    ->visible(fn (Get $get): bool => $get('decision') === 'reject')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->fillForm(fn (EntityDeletionRequest $record): array => [
                        'review_type' => $record->typeLabel(),
                        'review_company' => $record->company?->name,
                        'review_subject' => $record->snapshot_label.($record->snapshot_secondary ? ' - '.$record->snapshot_secondary : ''),
                        'review_status' => match ($record->status) {
                            'approved' => 'Approvata',
                            'rejected' => 'Respinta',
                            default => 'In attesa',
                        },
                        'review_requested_at' => $record->created_at?->format('d/m/Y H:i'),
                        'requested_reason' => $record->requested_reason,
                        'decision' => $record->status === 'rejected' ? 'reject' : 'approve',
                        'admin_notes' => $record->admin_notes,
                    ])
                    ->action(function (EntityDeletionRequest $record, array $data): void {
                        if (($data['decision'] ?? null) === 'approve') {
                            $record->approve(auth('admin')->user());

                            Notification::make()
                                ->title('Eliminazione approvata')
                                ->body($record->reviewLabel().' e stata gestita correttamente.')
                                ->success()
                                ->send();

                            return;
                        }

                        $record->reject((string) $data['admin_notes'], auth('admin')->user());

                        Notification::make()
                            ->title('Richiesta respinta')
                            ->body($record->reviewLabel().' e stata respinta correttamente.')
                            ->danger()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEntityDeletionRequests::route('/'),
        ];
    }
}
