<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadAllApproved')
                ->label('ZIP documenti')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->record->role === 'company')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'all']))
                ->openUrlInNewTab(),
            Action::make('downloadCompanyApproved')
                ->label('ZIP societa')
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->color('gray')
                ->visible(fn (): bool => $this->record->role === 'company')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'company']))
                ->openUrlInNewTab(),
            Action::make('downloadEmployeesApproved')
                ->label('ZIP dipendenti')
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->visible(fn (): bool => $this->record->role === 'company')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'employees']))
                ->openUrlInNewTab(),
            Action::make('downloadVehiclesApproved')
                ->label('ZIP veicoli')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (): bool => $this->record->role === 'company')
                ->url(fn (): string => route('admin.downloads.companies.show', [$this->record, 'vehicles']))
                ->openUrlInNewTab(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['role'] = 'company';
        $data['approved_at'] = ($data['approval_status'] ?? null) === 'approved'
            ? ($this->record->approved_at ?? now())
            : null;

        return $data;
    }
}
