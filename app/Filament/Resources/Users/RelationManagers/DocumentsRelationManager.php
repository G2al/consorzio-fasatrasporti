<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documenti società';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('template_id')
                    ->label('Template')
                    ->relationship('template', 'name', fn ($query) => $query->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('slug', 'societa')))
                    ->required()
                    ->preload()
                    ->searchable(),
                FileUpload::make('file_path')
                    ->label('File')
                    ->disk('public')
                    ->directory('uploaded-documents/societa')
                    ->downloadable()
                    ->openable()
                    ->required(),
                Select::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                    ])
                    ->default('pending')
                    ->required(),
                DatePicker::make('expiry_date')
                    ->label('Scadenza')
                    ->helperText('Opzionale. La societa la vede solo come informazione.'),
                DateTimePicker::make('approved_at')
                    ->label('Data approvazione')
                    ->helperText('Se lo stato e approvato viene compilata automaticamente.'),
                Textarea::make('admin_notes')
                    ->label('Note admin')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_path')
            ->columns([
                TextColumn::make('template.name')
                    ->label('Documento')
                    ->searchable(),
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
                TextColumn::make('updated_at')
                    ->label('Aggiornato')
                    ->dateTime('d/m/Y H:i'),
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
