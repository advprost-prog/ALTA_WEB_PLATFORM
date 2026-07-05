<?php

namespace App\Filament\Resources\AiSuggestions\Pages;

use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAiSuggestion extends ViewRecord
{
    protected static string $resource = AiSuggestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            AiSuggestionResource::applyAction(),
            AiSuggestionResource::rejectAction(),
        ];
    }
}
