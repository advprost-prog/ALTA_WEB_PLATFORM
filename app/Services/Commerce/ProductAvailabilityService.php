<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\ProductVariant;
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
    public function availabilityView(Product|ProductVariant $subject, int $requestedQuantity = 1): array
    {
        $settings = CommerceSetting::current();
        $variant = $this->resolveVariant($subject);
        $maxQuantity = $this->maxPurchasableQuantity($subject);
        $totalAvailable = $settings->multi_warehouse_enabled
            ? $this->aggregateAvailableQuantity($subject)
            : $this->defaultAvailableQuantity($subject);

        $canBeOrdered = $this->productCanBeOrdered($subject)
            && $maxQuantity >= max(1, $requestedQuantity);

        return [
            'is_available' => $canBeOrdered,
            'available_quantity' => $totalAvailable,
            'max_quantity' => $maxQuantity,
            'label' => $canBeOrdered ? 'В наявності' : 'Немає в наявності',
            'quantity_label' => $canBeOrdered && ! $settings->multi_warehouse_enabled
                ? 'Залишок: ' . $totalAvailable . ' ' . ($variant?->salesUnit?->short_name ?: 'шт')
                : null,
            'show_quantity' => $canBeOrdered && ! $settings->multi_warehouse_enabled,
        ];
    }

    public function maxPurchasableQuantity(Product|ProductVariant $subject): int
    {
        if (! $this->productCanBeOrdered($subject)) {
            return 0;
        }

        return $this->maxFulfillableQuantity($subject);
    }

    public function productCanBeOrdered(Product|ProductVariant $subject): bool
    {
        $product = $subject instanceof Product ? $subject : $subject->product;
        $variant = $this->resolveVariant($subject);

        return (bool) ($product?->is_active)
            && in_array((string) $product?->stock_status, ['in_stock', 'low_stock', 'preorder'], true)
            && ($variant === null || $variant->is_active);
    }

    public function defaultAvailableQuantity(Product|ProductVariant $subject): int
    {
        $settings = CommerceSetting::current();
        $balance = $this->balances($subject)
            ->first(fn (StockBalance $balance): bool => (int) $balance->warehouse_id === (int) $settings->default_warehouse_id
                && (bool) ($balance->warehouse?->is_active ?? true));

        return max(0, (int) floor((float) ($balance?->available_quantity ?? 0)));
    }

    public function aggregateAvailableQuantity(Product|ProductVariant $subject): int
    {
        return max(0, (int) floor($this->balances($subject)
            ->filter(fn (StockBalance $balance): bool => (bool) ($balance->warehouse?->is_active ?? true))
            ->sum(fn (StockBalance $balance): float => max(0, $balance->available_quantity))));
    }

    private function maxFulfillableQuantity(Product|ProductVariant $subject): int
    {
        if ($subject instanceof Product && $subject->relationLoaded('stockBalances')) {
            $settings = CommerceSetting::current();

            if (! $settings->multi_warehouse_enabled) {
                return $this->defaultAvailableQuantity($subject);
            }

            return max(0, (int) floor($this->balances($subject)
                ->filter(fn (StockBalance $balance): bool => (bool) ($balance->warehouse?->is_active ?? true))
                ->max(fn (StockBalance $balance): float => max(0, $balance->available_quantity)) ?? 0));
        }

        return $this->fulfillmentService->maxFulfillableQuantity($subject);
    }

    /**
     * @return Collection<int, StockBalance>
     */
    private function balances(Product|ProductVariant $subject): Collection
    {
        if ($subject instanceof ProductVariant) {
            if ($subject->relationLoaded('stockBalances')) {
                if ($subject->stockBalances->isNotEmpty()) {
                    return $subject->stockBalances;
                }

                return $subject->product?->stockBalances()
                    ->with('warehouse')
                    ->whereNull('product_variant_id')
                    ->get() ?? collect();
            }

            $balances = $subject->stockBalances()->with('warehouse')->get();

            if ($balances->isNotEmpty()) {
                return $balances;
            }

            return $subject->product?->stockBalances()
                ->with('warehouse')
                ->whereNull('product_variant_id')
                ->get() ?? collect();
        }

        $variant = $this->resolveVariant($subject);

        if ($variant) {
            if ($variant->relationLoaded('stockBalances') && $variant->stockBalances->isNotEmpty()) {
                return $variant->stockBalances;
            }

            $balances = $variant->stockBalances()->with('warehouse')->get();

            if ($balances->isNotEmpty()) {
                return $balances;
            }

            return $subject->stockBalances()->with('warehouse')->whereNull('product_variant_id')->get();
        }

        if ($subject->relationLoaded('stockBalances')) {
            return $subject->stockBalances;
        }

        return $subject->stockBalances()->with('warehouse')->get();
    }

    private function resolveVariant(Product|ProductVariant $subject): ?ProductVariant
    {
        if ($subject instanceof ProductVariant) {
            return $subject;
        }

        return $subject->resolveDefaultVariant();
    }
}
