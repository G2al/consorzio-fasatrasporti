<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentTemplates extends ManageRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
