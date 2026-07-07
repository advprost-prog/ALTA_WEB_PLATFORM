<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ProductResource::fillDefaultVariantFormState($data, $this->record);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (
            $this->record instanceof Product
            && $this->record->hasVariants()
            && array_key_exists('has_variants', $data)
            && ! (bool) $data['has_variants']
            && ! $this->record->canDisableVariants()
        ) {
            Notification::make()
                ->title('Не можна вимкнути варіанти')
                ->body('Не можна вимкнути варіанти, доки товар має більше одного активного SKU.')
                ->danger()
                ->send();

            $this->halt();
        }

        [$data, $this->defaultVariantPayload] = ProductResource::extractDefaultVariantPayload($data);

        return ProductResource::normalizeSkuForVariantMode($data);
    }

    protected function afterSave(): void
    {
        if (! $this->record instanceof Product) {
            return;
        }

        ProductResource::syncDefaultVariantFromPayload($this->record, $this->defaultVariantPayload);
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductResource::aiEnrichmentAction(),
            ProductResource::productImagePickerAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
