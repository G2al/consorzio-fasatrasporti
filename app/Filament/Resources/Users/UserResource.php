<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Users\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\Users\RelationManagers\VehiclesRelationManager;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Utenti';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Società';

    protected static ?string $modelLabel = 'società';

    protected static ?string $pluralModelLabel = 'società';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', 'company');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('role')
                    ->default('company'),
                TextInput::make('name')
                    ->label('Ragione sociale')
                    ->required()
                    ->maxLength(255),
                TextInput::make('responsible_name')
                    ->label('Responsabile')
                    ->maxLength(255),
                TextInput::make('vat_number')
                    ->label('Partita IVA')
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ragione sociale')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('responsible_name')
                    ->label('Responsabile')
                    ->searchable(),
                TextColumn::make('vat_number')
                    ->label('Partita IVA')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Registrata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            EmployeesRelationManager::class,
            VehiclesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
