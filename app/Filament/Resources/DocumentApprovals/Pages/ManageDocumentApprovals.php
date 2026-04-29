<?php

namespace App\Filament\Resources\DocumentApprovals\Pages;

use App\Filament\Resources\DocumentApprovals\DocumentApprovalResource;
use App\Models\UploadedDocument;
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
                ->badge(fn (): ?string => $this->pendingCount('societa'))
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'societa'))),
            'dipendenti' => Tab::make('Dipendenti')
                ->badge(fn (): ?string => $this->pendingCount('dipendenti'))
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'dipendenti'))),
            'veicoli' => Tab::make('Veicoli')
                ->badge(fn (): ?string => $this->pendingCount('veicoli'))
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->whereHas('template.section', fn (Builder $query) => $query->where('slug', 'veicoli'))),
        ];
    }

    private function pendingCount(?string $sectionSlug = null): ?string
    {
        $count = UploadedDocument::query()
            ->where('status', 'pending')
            ->when($sectionSlug, fn (Builder $query, string $slug): Builder => $query
                ->whereHas('template.section', fn (Builder $query): Builder => $query->where('slug', $slug)))
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
