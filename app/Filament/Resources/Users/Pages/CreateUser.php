<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = 'company';
        $data['approval_status'] ??= 'approved';
        $data['approved_at'] = $data['approval_status'] === 'approved' ? now() : null;

        return $data;
    }
}
