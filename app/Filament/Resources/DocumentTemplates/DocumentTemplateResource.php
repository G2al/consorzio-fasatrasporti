<?php

namespace App\Filament\Resources\DocumentTemplates;

use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ManageDocumentTemplates;
use App\Filament\Resources\DocumentTemplates\Pages\TemplateCompanies;
use App\Filament\Resources\DocumentTemplates\RelationManagers\SubtemplatesRelationManager;
use App\Models\DocumentTemplate;
use App\Models\DocumentCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Documenti';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Template documenti';

    protected static ?string $modelLabel = 'template documento';

    protected static ?string $pluralModelLabel = 'template documenti';

    public static function canAccess(): bool
    {
        return in_array(auth('admin')->user()?->role, ['admin', 'reviewer'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('section_id')
                    ->label('Sezione')
                    ->relationship('section', 'name')
                    ->required()
                    ->live()
                    ->preload()
                    ->searchable(),
                Select::make('category_id')
                    ->label('Categoria')
                    ->options(fn (Get $get): array => DocumentCategory::query()
                        ->when($get('section_id'), fn ($query, $sectionId) => $query->where('section_id', $sectionId))
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Facoltativa: nel frontend i documenti con categoria vengono raggruppati in blocchi.')
                    ->preload()
                    ->searchable(),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_required')
                    ->label('Obbligatorio')
                    ->default(true),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('section.name')
                    ->label('Sezione')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->placeholder('Primario')
                    ->sortable(),
                IconColumn::make('is_required')
                    ->label('Obbligatorio')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('section_id')
                    ->label('Sezione')
                    ->relationship('section', 'name'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Modifica'),
                Action::make('companies')
                    ->label('Societa')
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->color('gray')
                    ->url(fn (DocumentTemplate $record): string => static::getUrl('companies', ['record' => $record])),
                Action::make('downloadApproved')
                    ->label('Scarica approvati')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (DocumentTemplate $record): string => route('admin.downloads.templates.show', $record))
                    ->openUrlInNewTab(),
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
            'index' => ManageDocumentTemplates::route('/'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
            'companies' => TemplateCompanies::route('/{record}/societa'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            SubtemplatesRelationManager::class,
        ];
    }
}
