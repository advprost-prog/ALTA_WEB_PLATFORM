<?php

namespace App\Filament\Resources\StorefrontThemes\Pages;

use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStorefrontTheme extends ViewRecord
{
    protected static string $resource = StorefrontThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StorefrontThemeResource::previewAction(),
            StorefrontThemeResource::regenerateAction(),
            StorefrontThemeResource::activateAction(),
            EditAction::make(),
        ];
    }
}
