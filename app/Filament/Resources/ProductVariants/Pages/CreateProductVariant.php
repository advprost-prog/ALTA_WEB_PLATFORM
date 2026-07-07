<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateProductVariant extends CreateRecord
{
    protected static string $resource = ProductVariantResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            ProductVariantResource::validateDirectVariantProduct($data);
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
}
