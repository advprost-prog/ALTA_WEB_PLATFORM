<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @var array<string, mixed>
     */
    private array $defaultVariantPayload = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        [$data, $this->defaultVariantPayload] = ProductResource::extractDefaultVariantPayload($data);

        return ProductResource::normalizeSkuForVariantMode($data);
    }

    protected function afterCreate(): void
    {
        if (! $this->record instanceof Product) {
            return;
        }

        ProductResource::syncDefaultVariantFromPayload($this->record, $this->defaultVariantPayload);
    }
}
