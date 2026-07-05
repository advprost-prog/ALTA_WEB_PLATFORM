<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\StockBalance;
use Illuminate\Support\Collection;

class ProductAvailabilityService
{
    public function __construct(
        private readonly FulfillmentService $fulfillmentService,
    ) {}

    /**
     * @return array{is_available: bool, available_quantity: int, max_quantity: int, label: string, quantity_label: ?string, show_quantity: bool}
     */
    public function availabilityView(Product $product, int $requestedQuantity = 1): array
    {
        $settings = CommerceSetting::current();
        $maxQuantity = $this->maxPurchasableQuantity($product);
        $totalAvailable = $settings->multi_warehouse_enabled
            ? $this->aggregateAvailableQuantity($product)
            : $this->defaultAvailableQuantity($product);

        $canBeOrdered = $this->productCanBeOrdered($product)
            && $maxQuantity >= max(1, $requestedQuantity);

        return [
            'is_available' => $canBeOrdered,
            'available_quantity' => $totalAvailable,
            'max_quantity' => $maxQuantity,
            'label' => $canBeOrdered ? 'В наявності' : 'Немає в наявності',
            'quantity_label' => $canBeOrdered && ! $settings->multi_warehouse_enabled
                ? 'Залишок: ' . $totalAvailable . ' шт'
                : null,
            'show_quantity' => $canBeOrdered && ! $settings->multi_warehouse_enabled,
        ];
    }

    public function maxPurchasableQuantity(Product $product): int
    {
        if (! $this->productCanBeOrdered($product)) {
            return 0;
        }

        return $this->maxFulfillableQuantity($product);
    }

    public function productCanBeOrdered(Product $product): bool
    {
        return $product->is_active
            && in_array($product->stock_status, ['in_stock', 'low_stock', 'preorder'], true);
    }

    public function defaultAvailableQuantity(Product $product): int
    {
        $settings = CommerceSetting::current();
        $balance = $this->balances($product)
            ->first(fn (StockBalance $balance): bool => (int) $balance->warehouse_id === (int) $settings->default_warehouse_id
                && (bool) ($balance->warehouse?->is_active ?? true));

        return max(0, (int) floor((float) ($balance?->available_quantity ?? 0)));
    }

    public function aggregateAvailableQuantity(Product $product): int
    {
        return max(0, (int) floor($this->balances($product)
            ->filter(fn (StockBalance $balance): bool => (bool) ($balance->warehouse?->is_active ?? true))
            ->sum(fn (StockBalance $balance): float => max(0, $balance->available_quantity))));
    }

    private function maxFulfillableQuantity(Product $product): int
    {
        if ($product->relationLoaded('stockBalances')) {
            $settings = CommerceSetting::current();

            if (! $settings->multi_warehouse_enabled) {
                return $this->defaultAvailableQuantity($product);
            }

            return max(0, (int) floor($this->balances($product)
                ->filter(fn (StockBalance $balance): bool => (bool) ($balance->warehouse?->is_active ?? true))
                ->max(fn (StockBalance $balance): float => max(0, $balance->available_quantity)) ?? 0));
        }

        return $this->fulfillmentService->maxFulfillableQuantity($product);
    }

    /**
     * @return Collection<int, StockBalance>
     */
    private function balances(Product $product): Collection
    {
        if ($product->relationLoaded('stockBalances')) {
            return $product->stockBalances;
        }

        return $product->stockBalances()->with('warehouse')->get();
    }
}
