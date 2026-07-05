<?php

namespace App\Filament\Resources\StorefrontThemes\Pages;

use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStorefrontTheme extends EditRecord
{
    protected static string $resource = StorefrontThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StorefrontThemeResource::previewAction(),
            StorefrontThemeResource::regenerateAction(),
            StorefrontThemeResource::activateAction(),
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return StorefrontThemeResource::normalizeFormData($data);
    }
}
