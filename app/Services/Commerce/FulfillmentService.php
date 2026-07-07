<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\Warehouse;
use RuntimeException;

class FulfillmentService
{
    public function resolveWarehouse(Product|ProductVariant $subject, int $quantity, bool $lockForUpdate = false): Warehouse
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Кількість товару має бути більшою за нуль.');
        }

        $settings = CommerceSetting::current();
        $defaultWarehouse = $this->activeDefaultWarehouse($settings);

        if (! $settings->multi_warehouse_enabled) {
            if (! $defaultWarehouse || $this->availableInWarehouse($subject, $settings->default_warehouse_id, $lockForUpdate) + 0.001 < $quantity) {
                throw new RuntimeException('Недостатньо товару в наявності.');
            }

            return $defaultWarehouse;
        }

        if ($defaultWarehouse && $this->availableInWarehouse($subject, $defaultWarehouse->id, $lockForUpdate) + 0.001 >= $quantity) {
            return $defaultWarehouse;
        }

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->when($settings->default_warehouse_id, fn ($query) => $query->whereKeyNot($settings->default_warehouse_id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($warehouses as $warehouse) {
            if ($this->availableInWarehouse($subject, $warehouse->id, $lockForUpdate) + 0.001 >= $quantity) {
                return $warehouse;
            }
        }

        throw new RuntimeException('Недостатньо товару в наявності.');
    }

    public function maxFulfillableQuantity(Product|ProductVariant $subject): int
    {
        $settings = CommerceSetting::current();
        [$product, $variant] = $this->productAndVariant($subject);

        if (! $settings->multi_warehouse_enabled) {
            return max(0, (int) floor($this->availableInWarehouse($subject, (int) $settings->default_warehouse_id)));
        }

        $warehouseIds = Warehouse::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        $maxAvailable = $this->effectiveBalances($product, $variant, $warehouseIds->all())
            ->max(fn (StockBalance $balance): float => max(0, $balance->available_quantity));

        return max(0, (int) floor((float) $maxAvailable));
    }

    public function totalAvailableQuantity(Product|ProductVariant $subject): int
    {
        $settings = CommerceSetting::current();
        [$product, $variant] = $this->productAndVariant($subject);

        if (! $settings->multi_warehouse_enabled) {
            return max(0, (int) floor($this->availableInWarehouse($subject, (int) $settings->default_warehouse_id)));
        }

        $warehouseIds = Warehouse::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        $totalAvailable = $this->effectiveBalances($product, $variant, $warehouseIds->all())
            ->sum(fn (StockBalance $balance): float => max(0, $balance->available_quantity));

        return max(0, (int) floor((float) $totalAvailable));
    }

    private function activeDefaultWarehouse(CommerceSetting $settings): ?Warehouse
    {
        if (! $settings->default_warehouse_id) {
            return null;
        }

        return Warehouse::query()
            ->whereKey($settings->default_warehouse_id)
            ->where('is_active', true)
            ->first();
    }

    private function availableInWarehouse(Product|ProductVariant $subject, int $warehouseId, bool $lockForUpdate = false): float
    {
        if (! $warehouseId) {
            return 0.0;
        }

        $product = $this->resolveProduct($subject);
        $variant = $this->resolveVariant($subject);

        $balance = $this->effectiveBalances($product, $variant, [$warehouseId], $lockForUpdate)->first();

        if (! $balance) {
            return 0.0;
        }

        return max(0, $balance->available_quantity);
    }

    private function resolveProduct(Product|ProductVariant $subject): Product
    {
        return $subject instanceof Product ? $subject : $subject->product;
    }

    private function resolveVariant(Product|ProductVariant $subject): ?ProductVariant
    {
        return $subject instanceof ProductVariant ? $subject : $subject->resolveDefaultVariant();
    }

    /**
     * @return array{0: Product, 1: ProductVariant|null}
     */
    private function productAndVariant(Product|ProductVariant $subject): array
    {
        return [$this->resolveProduct($subject), $this->resolveVariant($subject)];
    }

    /**
     * @param  array<int, int>  $warehouseIds
     * @return \Illuminate\Support\Collection<int, StockBalance>
     */
    private function effectiveBalances(Product $product, ?ProductVariant $variant, array $warehouseIds, bool $lockForUpdate = false)
    {
        $query = StockBalance::query()
            ->where('product_id', $product->id)
            ->whereIn('warehouse_id', $warehouseIds);

        if ($variant) {
            $query->where(function ($builder) use ($variant): void {
                $builder->where('product_variant_id', $variant->id)
                    ->orWhereNull('product_variant_id');
            });
        } else {
            $query->whereNull('product_variant_id');
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->groupBy('warehouse_id')
            ->map(function ($group) use ($variant) {
                if (! $variant) {
                    return $group->first();
                }

                return $group->firstWhere('product_variant_id', $variant->id)
                    ?? $group->firstWhere('product_variant_id', null);
            })
            ->filter()
            ->values();
    }
}
