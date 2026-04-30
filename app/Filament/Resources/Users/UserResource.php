<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\DocumentOverview;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Users\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\Users\RelationManagers\VehiclesRelationManager;
use App\Models\User;
use App\Services\MissingDocumentsReportService;
use App\Services\TelegramNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

    public static function canAccess(): bool
    {
        return in_array(auth('admin')->user()?->role, ['admin', 'reviewer'], true);
    }

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
                TextInput::make('responsible_phone')
                    ->label('Telefono responsabile')
                    ->tel()
                    ->maxLength(30),
                TextInput::make('vat_number')
                    ->label('Partita IVA')
                    ->maxLength(255),
                Select::make('approval_status')
                    ->label('Stato approvazione')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvata',
                    ])
                    ->default('approved')
                    ->required(),
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
                TextColumn::make('responsible_phone')
                    ->label('Telefono')
                    ->searchable(),
                TextColumn::make('vat_number')
                    ->label('Partita IVA')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('approval_status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'approved' ? 'Approvata' : 'In attesa')
                    ->color(fn (string $state): string => $state === 'approved' ? 'success' : 'warning'),
                TextColumn::make('created_at')
                    ->label('Registrata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approva')
                    ->tooltip('Approva societa')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->iconButton()
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->approval_status !== 'approved')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'approval_status' => 'approved',
                            'approved_at' => now(),
                        ])->save();

                        app(TelegramNotifier::class)->notifyCompanyApproved($record);

                        Notification::make()
                            ->title('Societa approvata')
                            ->success()
                            ->send();
                    }),
                ActionGroup::make([
                    Action::make('documentOverview')
                        ->label('Panoramica documenti')
                        ->icon(Heroicon::OutlinedClipboardDocumentList)
                        ->color('gray')
                        ->url(fn (User $record): string => static::getUrl('documents', ['record' => $record])),
                    Action::make('downloadApproved')
                        ->label('Scarica ZIP')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('gray')
                        ->visible(fn (User $record): bool => $record->role === 'company')
                        ->url(fn (User $record): string => route('admin.downloads.companies.show', [$record, 'all']))
                        ->openUrlInNewTab(),
                    Action::make('downloadPdf')
                        ->label('Scarica PDF riepilogo')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->color('gray')
                        ->visible(fn (User $record): bool => $record->role === 'company')
                        ->url(fn (User $record): string => route('admin.downloads.companies.pdf', [$record, 'all']))
                        ->openUrlInNewTab(),
                    Action::make('sendMissingEmails')
                        ->label('Invia email mancanti')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->color('warning')
                        ->visible(fn (User $record): bool => $record->role === 'company' && filled($record->email))
                        ->requiresConfirmation()
                        ->modalHeading('Inviare riepilogo documenti mancanti?')
                        ->modalDescription('Verranno inviate fino a 3 email separate alla societa: societa, dipendenti e veicoli. Le sezioni senza mancanti non vengono inviate.')
                        ->action(function (User $record): void {
                            $sent = app(MissingDocumentsReportService::class)->sendManual($record);
                            $total = array_sum($sent);

                            if ($total === 0) {
                                Notification::make()
                                    ->title('Nessuna email inviata')
                                    ->body('Non risultano documenti mancanti o scaduti per questa societa.')
                                    ->info()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Email riepilogo inviate')
                                ->body('Inclusi '.$total.' documenti: societa '.$sent['societa'].', dipendenti '.$sent['dipendenti'].', veicoli '.$sent['veicoli'].'.')
                                ->success()
                                ->send();
                        }),
                ])
                    ->label('Documenti')
                    ->tooltip('Documenti ed esportazioni')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->iconButton()
                    ->color('gray'),
                ActionGroup::make([
                    EditAction::make()
                        ->label('Modifica')
                        ->icon(Heroicon::OutlinedPencilSquare),
                    DeleteAction::make()
                        ->label('Elimina')
                        ->icon(Heroicon::OutlinedTrash),
                ])
                    ->label('Azioni')
                    ->tooltip('Azioni societa')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->iconButton()
                    ->color('gray'),
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
            'documents' => DocumentOverview::route('/{record}/documenti'),
        ];
    }
}
