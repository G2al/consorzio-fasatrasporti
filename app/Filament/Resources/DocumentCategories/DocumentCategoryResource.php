<?php

namespace App\Filament\Resources\DocumentCategories;

use App\Filament\Resources\DocumentCategories\Pages\ManageDocumentCategories;
use App\Models\DocumentCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentCategoryResource extends Resource
{
    protected static ?string $model = DocumentCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Documenti';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Categorie documenti';

    protected static ?string $modelLabel = 'categoria documento';

    protected static ?string $pluralModelLabel = 'categorie documenti';

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->role === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('section_id')
                    ->label('Sezione')
                    ->relationship('section', 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label('Ordine')
                    ->numeric()
                    ->default(0),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('section.name')
                    ->label('Sezione')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('templates_count')
                    ->counts('templates')
                    ->label('Documenti')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordine')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('section_id')
                    ->label('Sezione')
                    ->relationship('section', 'name'),
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

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentCategories::route('/'),
        ];
    }
}
