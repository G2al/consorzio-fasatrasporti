<?php

namespace App\Filament\Resources\DocumentExemptions\Pages;

use App\Filament\Resources\DocumentExemptions\DocumentExemptionResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageDocumentExemptions extends ManageRecords
{
    protected static string $resource = DocumentExemptionResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('In attesa')
                ->query(fn (Builder $query): Builder => $query->where('status', 'pending')),
            'approved' => Tab::make('Approvate')
                ->query(fn (Builder $query): Builder => $query->where('status', 'approved')),
            'rejected' => Tab::make('Rifiutate')
                ->query(fn (Builder $query): Builder => $query->where('status', 'rejected')),
            'all' => Tab::make('Tutte'),
        ];
    }
}
