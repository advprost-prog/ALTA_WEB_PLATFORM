<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use RuntimeException;

class FulfillmentService
{
    public function resolveWarehouse(Product $product, int $quantity, bool $lockForUpdate = false): Warehouse
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Кількість товару має бути більшою за нуль.');
        }

        $settings = CommerceSetting::current();
        $defaultWarehouse = $this->activeDefaultWarehouse($settings);

        if (! $settings->multi_warehouse_enabled) {
            if (! $defaultWarehouse || $this->availableInWarehouse($product, $settings->default_warehouse_id, $lockForUpdate) + 0.001 < $quantity) {
                throw new RuntimeException('Недостатньо товару в наявності.');
            }

            return $defaultWarehouse;
        }

        if ($defaultWarehouse && $this->availableInWarehouse($product, $defaultWarehouse->id, $lockForUpdate) + 0.001 >= $quantity) {
            return $defaultWarehouse;
        }

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->when($settings->default_warehouse_id, fn ($query) => $query->whereKeyNot($settings->default_warehouse_id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($warehouses as $warehouse) {
            if ($this->availableInWarehouse($product, $warehouse->id, $lockForUpdate) + 0.001 >= $quantity) {
                return $warehouse;
            }
        }

        throw new RuntimeException('Недостатньо товару в наявності.');
    }

    public function maxFulfillableQuantity(Product $product): int
    {
        $settings = CommerceSetting::current();

        if (! $settings->multi_warehouse_enabled) {
            return max(0, (int) floor($this->availableInWarehouse($product, (int) $settings->default_warehouse_id)));
        }

        $warehouseIds = Warehouse::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        $maxAvailable = StockBalance::query()
            ->where('product_id', $product->id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->max(fn (StockBalance $balance): float => max(0, $balance->available_quantity));

        return max(0, (int) floor((float) $maxAvailable));
    }

    public function totalAvailableQuantity(Product $product): int
    {
        $settings = CommerceSetting::current();

        if (! $settings->multi_warehouse_enabled) {
            return max(0, (int) floor($this->availableInWarehouse($product, (int) $settings->default_warehouse_id)));
        }

        $warehouseIds = Warehouse::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        $totalAvailable = StockBalance::query()
            ->where('product_id', $product->id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
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

    private function availableInWarehouse(Product $product, int $warehouseId, bool $lockForUpdate = false): float
    {
        if (! $warehouseId) {
            return 0.0;
        }

        $query = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if (! $balance) {
            return 0.0;
        }

        return max(0, $balance->available_quantity);
    }
}
