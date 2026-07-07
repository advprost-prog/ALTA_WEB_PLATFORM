<?php

namespace App\Filament\Resources\SystemAddons\Pages;

use App\Filament\Resources\SystemAddons\SystemAddonResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSystemAddon extends ViewRecord
{
    protected static string $resource = SystemAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to addons')
                ->url(SystemAddonResource::getUrl()),
        ];
    }
}
