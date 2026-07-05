<?php

namespace App\Filament\Resources\AiSuggestions\Pages;

use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use App\Models\AiSuggestion;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditAiSuggestion extends EditRecord
{
    protected static string $resource = AiSuggestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AiSuggestionResource::applyAction(),
            AiSuggestionResource::rejectAction(),
            ViewAction::make(),
        ];
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('update', $this->getRecord()), 403);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['suggested_payload_json'] = isset($data['suggested_payload'])
            ? json_encode($data['suggested_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var AiSuggestion $record */
        $record = $this->getRecord();

        if (! $record->canBeEdited()) {
            throw ValidationException::withMessages([
                'data.suggested_value' => 'Застосовані та відхилені AI-пропозиції редагувати не можна.',
            ]);
        }

        if (array_key_exists('suggested_payload_json', $data)) {
            $json = trim((string) $data['suggested_payload_json']);
            unset($data['suggested_payload_json']);

            if ($json !== '') {
                $decoded = json_decode($json, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    throw ValidationException::withMessages([
                        'data.suggested_payload_json' => 'Payload має бути валідним JSON object або array.',
                    ]);
                }

                $data['suggested_payload'] = $decoded;
            }
        }

        $data['status'] ??= $record->status;

        if (($data['status'] ?? null) === AiSuggestion::STATUS_APPLIED && $record->status !== AiSuggestion::STATUS_APPLIED) {
            throw ValidationException::withMessages([
                'data.status' => 'Статус applied встановлюється тільки через Apply.',
            ]);
        }

        $data['edited_by'] = auth()->id();
        $data['edited_at'] = now();

        return $data;
    }
}
