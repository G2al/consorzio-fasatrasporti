<?php

namespace App\Filament\Resources\DocumentExemptions;

use App\Filament\Resources\DocumentExemptions\Pages\ManageDocumentExemptions;
use App\Models\AuditLog;
use App\Models\DocumentExemption;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentExemptionResource extends Resource
{
    protected static ?string $model = DocumentExemption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Esenzioni documenti';

    protected static string|\UnitEnum|null $navigationGroup = 'Documenti';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'esenzione documento';

    protected static ?string $pluralModelLabel = 'esenzioni documenti';

    public static function canAccess(): bool
    {
        return in_array(auth('admin')->user()?->role, ['admin', 'reviewer'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'template.section',
                'exemptable' => fn (MorphTo $morphTo): MorphTo => $morphTo->morphWith([
                    Employee::class => ['user'],
                    Vehicle::class => ['user'],
                ]),
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
                TextColumn::make('company_display')
                    ->label('Societa / elemento')
                    ->state(fn (DocumentExemption $record): string => static::companyLabel($record))
                    ->description(fn (DocumentExemption $record): ?string => static::elementDescription($record)),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Esente approvata',
                        'rejected' => 'Esenzione rifiutata',
                        default => 'In attesa',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('requested_reason')
                    ->label('Motivo societa')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('admin_notes')
                    ->label('Note admin')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Richiesta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvate',
                        'rejected' => 'Rifiutate',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approva')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (DocumentExemption $record): bool => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(function (DocumentExemption $record): void {
                        $record->update([
                            'status' => 'approved',
                            'reviewed_at' => now(),
                            'admin_notes' => null,
                        ]);

                        AuditLog::record('document_exemption.approved', $record->fresh(['template', 'exemptable']), 'Esenzione documento approvata', [
                            'template' => $record->template->name,
                        ]);

                        Notification::make()
                            ->title('Esenzione approvata')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Rifiuta')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (DocumentExemption $record): bool => $record->status !== 'rejected')
                    ->form([
                        Textarea::make('admin_notes')
                            ->label('Motivo rifiuto')
                            ->rows(4)
                            ->required(),
                    ])
                    ->action(function (DocumentExemption $record, array $data): void {
                        $record->update([
                            'status' => 'rejected',
                            'reviewed_at' => now(),
                            'admin_notes' => $data['admin_notes'],
                        ]);

                        AuditLog::record('document_exemption.rejected', $record->fresh(['template', 'exemptable']), 'Esenzione documento rifiutata', [
                            'template' => $record->template->name,
                            'notes' => $data['admin_notes'],
                        ]);

                        Notification::make()
                            ->title('Esenzione rifiutata')
                            ->danger()
                            ->send();
                    }),
                Action::make('restore')
                    ->label('Ripristina')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (DocumentExemption $record): bool => $record->status === 'approved')
                    ->requiresConfirmation()
                    ->action(function (DocumentExemption $record): void {
                        AuditLog::record('document_exemption.restored', $record, 'Esenzione documento ripristinata', [
                            'template' => $record->template->name,
                        ]);

                        $record->delete();

                        Notification::make()
                            ->title('Documento ripristinato')
                            ->body('Il documento torna visibile e caricabile nel frontend.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentExemptions::route('/'),
        ];
    }

    protected static function companyLabel(DocumentExemption $record): string
    {
        return $record->companyUser()?->name ?? 'Societa non disponibile';
    }

    protected static function elementDescription(DocumentExemption $record): ?string
    {
        $exemptable = $record->exemptable;

        return match (true) {
            $exemptable instanceof Employee => 'Dipendente: '.trim("{$exemptable->first_name} {$exemptable->last_name}"),
            $exemptable instanceof Vehicle => 'Veicolo: '.$exemptable->plate.' ('.$exemptable->capacity.' posti)',
            $exemptable instanceof User => 'Societa',
            default => null,
        };
    }
}
