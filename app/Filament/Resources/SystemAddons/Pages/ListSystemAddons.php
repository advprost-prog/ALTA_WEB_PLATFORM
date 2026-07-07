<?php

namespace App\Filament\Resources\SystemAddons\Pages;

use App\Filament\Resources\SystemAddons\SystemAddonResource;
use App\Support\Addons\AddonManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSystemAddons extends ListRecords
{
    protected static string $resource = SystemAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discover')
                ->label('Discover / rescan')
                ->action(function (): void {
                    $result = app(AddonManager::class)->discover();

                    Notification::make()
                        ->title('Addon discovery complete')
                        ->body('Discovered: '.$result['discovered'].'; invalid: '.$result['invalid'].'; duplicates: '.$result['duplicates'])
                        ->success()
                        ->send();
                }),
        ];
    }
}
