<?php

namespace App\Filament\Resources\EntityDeletionRequests\Pages;

use App\Filament\Resources\EntityDeletionRequests\EntityDeletionRequestResource;
use App\Models\Employee;
use App\Models\EntityDeletionRequest;
use App\Models\Vehicle;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageEntityDeletionRequests extends ManageRecords
{
    protected static string $resource = EntityDeletionRequestResource::class;

    public function getTabs(): array
    {
        return [
            'dipendenti' => Tab::make('Dipendenti')
                ->badge(fn (): ?string => $this->pendingCount(Employee::class))
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->where('deletable_type', Employee::class)),
            'veicoli' => Tab::make('Veicoli')
                ->badge(fn (): ?string => $this->pendingCount(Vehicle::class))
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->where('deletable_type', Vehicle::class)),
        ];
    }

    private function pendingCount(string $type): ?string
    {
        $count = EntityDeletionRequest::query()
            ->where('status', 'pending')
            ->where('deletable_type', $type)
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
