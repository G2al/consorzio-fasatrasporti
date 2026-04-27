<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\VehicleCapacity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $title = 'Veicoli';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('plate')
                    ->label('Targa')
                    ->required()
                    ->maxLength(255),
                Select::make('capacity')
                    ->label('Capienza')
                    ->options(fn (): array => collect(VehicleCapacity::query()
                        ->orderBy('seats')
                        ->pluck('seats')
                        ->all() ?: VehicleCapacity::VALUES)
                        ->mapWithKeys(fn (int $seats): array => [$seats => $seats.' posti'])
                        ->all())
                    ->native(false)
                    ->required()
                    ->searchable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plate')
            ->columns([
                TextColumn::make('plate')
                    ->label('Targa')
                    ->searchable(),
                TextColumn::make('capacity')
                    ->label('Capienza')
                    ->formatStateUsing(fn (int|string|null $state): string => $state ? $state.' posti' : '-')
                    ->sortable(),
                TextColumn::make('documents_count')
                    ->counts('documents')
                    ->label('Documenti'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
