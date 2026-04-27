<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|\UnitEnum|null $navigationGroup = 'Impostazioni';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'audit log';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('readable_action')
                    ->label('Azione')
                    ->badge()
                    ->color(fn (AuditLog $record): string => match (true) {
                        str_starts_with($record->action, 'document.rejected'),
                        str_ends_with($record->action, '.deleted') => 'danger',
                        str_starts_with($record->action, 'document.approved') => 'success',
                        str_starts_with($record->action, 'document.uploaded') => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('readable_summary')
                    ->label('Evento')
                    ->searchable(['description', 'action'])
                    ->wrap(),
                TextColumn::make('company.name')
                    ->label('Societa')
                    ->searchable(),
                TextColumn::make('readable_actor')
                    ->label('Utente')
                    ->placeholder('Sistema')
                    ->toggleable(),
                TextColumn::make('readable_metadata')
                    ->label('Dettagli')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('action')
                    ->label('Codice evento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('action')
                    ->label('Azione')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->map(fn (string $action): string => AuditLog::labelForAction($action))
                        ->all())
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
