<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                TextInput::make('brand_model')
                    ->label('Marca e modello')
                    ->required()
                    ->maxLength(255),
                TextInput::make('plate')
                    ->label('Targa')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plate')
            ->columns([
                TextColumn::make('brand_model')
                    ->label('Marca e modello')
                    ->searchable(),
                TextColumn::make('plate')
                    ->label('Targa')
                    ->searchable(),
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
