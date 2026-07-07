<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProductVariant extends EditRecord
{
    protected static string $resource = ProductVariantResource::class;

    protected function authorizeAccess(): void
    {
        if (ProductVariantResource::canEdit($this->getRecord())) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('SKU простого товару недоступний')
            ->body(ProductVariantResource::SIMPLE_PRODUCT_SKU_MESSAGE)
            ->send();

        abort(403, ProductVariantResource::SIMPLE_PRODUCT_SKU_MESSAGE);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            ProductVariantResource::validateDirectVariantProduct([
                ...$data,
                'product_id' => $data['product_id'] ?? $this->getRecord()->product_id,
            ]);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('SKU простого товару недоступний')
                ->body(ProductVariantResource::SIMPLE_PRODUCT_SKU_MESSAGE)
                ->send();

            throw $exception;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn (ProductVariant $record): bool => ProductVariantResource::canDelete($record)),
        ];
    }
}
