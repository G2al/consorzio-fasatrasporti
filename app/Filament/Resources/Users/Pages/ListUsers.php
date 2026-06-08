<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\CompanyCredentialsMailService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('companySectionOverview')
                ->label('Panoramica totale')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(fn (): string => UserResource::getUrl('companyOverview')),
            Action::make('sendCredentialsEmails')
                ->label('Invia credenziali')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('warning')
                ->visible(fn (): bool => auth('admin')->user()?->role === 'admin')
                ->requiresConfirmation()
                ->modalHeading('Inviare le credenziali alle societa selezionate?')
                ->modalDescription('Le societa selezionate riceveranno le credenziali di accesso tramite email.')
                ->form([
                    CheckboxList::make('emails')
                        ->label('Societa da contattare')
                        ->options(fn (): array => app(CompanyCredentialsMailService::class)->selectableOptions())
                        ->searchable()
                        ->columns(2)
                        ->bulkToggleable()
                        ->required()
                        ->helperText('Questo elenco proviene da un file fisso preparato manualmente. Se viene aggiunta una societa oppure cambiano email o password, e necessario contattare il programmatore per aggiornare i dati.'),
                ])
                ->action(function (array $data): void {
                    try {
                        $selectedEmails = $data['emails'] ?? [];

                        if ($selectedEmails === []) {
                            Notification::make()
                                ->title('Nessuna societa selezionata')
                                ->warning()
                                ->send();

                            return;
                        }

                        $result = app(CompanyCredentialsMailService::class)->sendManual($selectedEmails);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Invio credenziali non riuscito')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($result['sent'] === 0) {
                        Notification::make()
                            ->title('Nessuna email inviata')
                            ->body('Controlla il JSON: non risultano credenziali valide da inviare.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Credenziali inviate')
                        ->body('Inviate '.$result['sent'].' email, saltate '.$result['skipped'].'. Societa abbinate: '.$result['matched'].', indirizzi esterni: '.$result['unmatched'].'.')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
