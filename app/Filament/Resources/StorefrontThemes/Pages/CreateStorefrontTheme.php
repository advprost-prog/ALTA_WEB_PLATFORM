<?php

namespace App\Filament\Resources\StorefrontThemes\Pages;

use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStorefrontTheme extends CreateRecord
{
    protected static string $resource = StorefrontThemeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return StorefrontThemeResource::normalizeFormData($data);
    }
}
