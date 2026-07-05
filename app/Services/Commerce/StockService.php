<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    public function setQuantity(
        Product $product,
        int $warehouseId,
        float $newQuantity,
        ?string $note = null,
        ?int $createdBy = null,
        ?Model $related = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $warehouseId, $newQuantity, $note, $createdBy, $related): ?StockMovement {
            $balance = $this->lockedBalance($product, $warehouseId);

            $this->assertQuantityIsAllowed($newQuantity, (float) $balance->reserved_quantity);

            $oldQuantity = (float) $balance->quantity;
            $delta = $newQuantity - $oldQuantity;

            if (abs($delta) < 0.001) {
                return null;
            }

            $balance->forceFill(['quantity' => $newQuantity])->saveQuietly();
            $this->syncProductStockIfDefaultWarehouse($product, $warehouseId, $newQuantity);

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'quantity' => $delta,
                'balance_after' => $newQuantity,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'note' => $note,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function applyDelta(
        Product $product,
        int $warehouseId,
        float $delta,
        string $type,
        ?string $note = null,
        ?int $createdBy = null,
        ?Model $related = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $warehouseId, $delta, $type, $note, $createdBy, $related): ?StockMovement {
            if (abs($delta) < 0.001) {
                return null;
            }

            $balance = $this->lockedBalance($product, $warehouseId);
            $newQuantity = (float) $balance->quantity + $delta;

            $this->assertQuantityIsAllowed($newQuantity, (float) $balance->reserved_quantity);

            $balance->forceFill(['quantity' => $newQuantity])->saveQuietly();
            $this->syncProductStockIfDefaultWarehouse($product, $warehouseId, $newQuantity);

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $newQuantity,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'note' => $note,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transfer(
        Product $product,
        int $sourceWarehouseId,
        int $targetWarehouseId,
        float $quantity,
        ?string $note = null,
        ?int $createdBy = null,
    ): array {
        return DB::transaction(function () use ($product, $sourceWarehouseId, $targetWarehouseId, $quantity, $note, $createdBy): array {
            if ($sourceWarehouseId === $targetWarehouseId) {
                throw new RuntimeException('Source and target warehouses must be different.');
            }

            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity must be greater than zero.');
            }

            $source = $this->lockedBalance($product, $sourceWarehouseId);
            $target = $this->lockedBalance($product, $targetWarehouseId);

            if (((float) $source->quantity - (float) $source->reserved_quantity) + 0.001 < $quantity) {
                throw new RuntimeException('Insufficient available stock in source warehouse.');
            }

            $sourceAfter = (float) $source->quantity - $quantity;
            $targetAfter = (float) $target->quantity + $quantity;

            $this->assertQuantityIsAllowed($sourceAfter, (float) $source->reserved_quantity);
            $this->assertQuantityIsAllowed($targetAfter, (float) $target->reserved_quantity);

            $source->forceFill(['quantity' => $sourceAfter])->saveQuietly();
            $target->forceFill(['quantity' => $targetAfter])->saveQuietly();

            $this->syncProductStockIfDefaultWarehouse($product, $sourceWarehouseId, $sourceAfter);
            $this->syncProductStockIfDefaultWarehouse($product, $targetWarehouseId, $targetAfter);

            $movementOut = StockMovement::query()->create([
                'product_id' => $product->id,
                'warehouse_id' => $sourceWarehouseId,
                'type' => StockMovement::TYPE_TRANSFER_OUT,
                'quantity' => -1 * $quantity,
                'balance_after' => $sourceAfter,
                'note' => $note,
                'created_by' => $createdBy,
            ]);

            $movementIn = StockMovement::query()->create([
                'product_id' => $product->id,
                'warehouse_id' => $targetWarehouseId,
                'type' => StockMovement::TYPE_TRANSFER_IN,
                'quantity' => $quantity,
                'balance_after' => $targetAfter,
                'note' => $note,
                'created_by' => $createdBy,
            ]);

            return [
                'out' => $movementOut,
                'in' => $movementIn,
            ];
        });
    }

    private function lockedBalance(Product $product, int $warehouseId): StockBalance
    {
        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        $settings = CommerceSetting::current();
        $initialQuantity = (int) $settings->default_warehouse_id === $warehouseId
            ? (float) $product->stock
            : 0.0;

        $balance = new StockBalance([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => $initialQuantity,
            'reserved_quantity' => 0,
        ]);

        $balance->saveQuietly();

        return $balance->refresh();
    }

    private function assertQuantityIsAllowed(float $quantity, float $reservedQuantity): void
    {
        if ($quantity < -0.001) {
            throw new RuntimeException('Stock quantity cannot be negative.');
        }

        if ($quantity + 0.001 < $reservedQuantity) {
            throw new RuntimeException('Stock quantity cannot be lower than reserved quantity.');
        }
    }

    private function syncProductStockIfDefaultWarehouse(Product $product, int $warehouseId, float $quantity): void
    {
        $settings = CommerceSetting::current();

        if ((int) $settings->default_warehouse_id !== $warehouseId) {
            return;
        }

        $product->forceFill(['stock' => max(0, (int) floor($quantity))])->saveQuietly();
    }
}
