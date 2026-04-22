<?php

namespace App\Filament\Resources\DocumentApprovals\Pages;

use App\Filament\Resources\DocumentApprovals\DocumentApprovalResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageDocumentApprovals extends ManageRecords
{
    protected static string $resource = DocumentApprovalResource::class;

    public function getTabs(): array
    {
        return [
            'societa' => Tab::make('Societa')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'societa'))),
            'dipendenti' => Tab::make('Dipendenti')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'dipendenti'))),
            'veicoli' => Tab::make('Veicoli')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'veicoli'))),
        ];
    }
}
