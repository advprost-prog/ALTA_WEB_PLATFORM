<?php

namespace App\Filament\Resources\TaxProfiles\Pages;

use App\Filament\Resources\TaxProfiles\TaxProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxProfile extends EditRecord
{
    protected static string $resource = TaxProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
