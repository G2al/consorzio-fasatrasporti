<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringDocumentsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Documenti in scadenza')
            ->query($this->query())
            ->paginated(false)
            ->columns([
                TextColumn::make('template.section.name')
                    ->label('Sezione')
                    ->badge(),
                TextColumn::make('template.name')
                    ->label('Documento')
                    ->searchable(),
                TextColumn::make('documentable.name')
                    ->label('Societa / elemento')
                    ->formatStateUsing(fn (UploadedDocument $record): string => $this->documentableLabel($record)),
                TextColumn::make('expiry_date')
                    ->label('Scadenza')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (UploadedDocument $record): string => $record->expiry_date?->isPast() ? 'danger' : 'warning'),
            ]);
    }

    private function query(): Builder
    {
        return UploadedDocument::query()
            ->with(['template.section', 'documentable'])
            ->where('status', 'approved')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(60))
            ->orderBy('expiry_date');
    }

    private function documentableLabel(UploadedDocument $record): string
    {
        $documentable = $record->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof Vehicle => "{$documentable->brand_model} ({$documentable->plate})",
            default => 'Elemento eliminato',
        };
    }
}
