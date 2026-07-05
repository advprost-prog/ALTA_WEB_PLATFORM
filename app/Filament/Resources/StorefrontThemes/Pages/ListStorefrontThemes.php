<?php

namespace App\Filament\Resources\StorefrontThemes\Pages;

use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStorefrontThemes extends ListRecords
{
    protected static string $resource = StorefrontThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
