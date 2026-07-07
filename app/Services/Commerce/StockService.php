<?php

namespace App\Services\Commerce;

use App\Models\CommerceSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    public function setQuantity(
        Product|ProductVariant $subject,
        int $warehouseId,
        float $newQuantity,
        ?string $note = null,
        ?int $createdBy = null,
        ?Model $related = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($subject, $warehouseId, $newQuantity, $note, $createdBy, $related): ?StockMovement {
            [$product, $variant] = $this->resolveContext($subject);
            $balance = $this->lockedBalance($product, $warehouseId, $variant?->id);

            $this->assertQuantityIsAllowed($newQuantity, (float) $balance->reserved_quantity);

            $oldQuantity = (float) $balance->quantity;
            $delta = $newQuantity - $oldQuantity;

            if (abs($delta) < 0.001) {
                return null;
            }

            $balance->forceFill(['quantity' => $newQuantity])->saveQuietly();
            $this->syncProductStockIfDefaultWarehouse($product, $variant, $warehouseId, $newQuantity);

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
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
        Product|ProductVariant $subject,
        int $warehouseId,
        float $delta,
        string $type,
        ?string $note = null,
        ?int $createdBy = null,
        ?Model $related = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($subject, $warehouseId, $delta, $type, $note, $createdBy, $related): ?StockMovement {
            if (abs($delta) < 0.001) {
                return null;
            }

            [$product, $variant] = $this->resolveContext($subject);
            $balance = $this->lockedBalance($product, $warehouseId, $variant?->id);
            $newQuantity = (float) $balance->quantity + $delta;

            $this->assertQuantityIsAllowed($newQuantity, (float) $balance->reserved_quantity);

            $balance->forceFill(['quantity' => $newQuantity])->saveQuietly();
            $this->syncProductStockIfDefaultWarehouse($product, $variant, $warehouseId, $newQuantity);

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
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
        Product|ProductVariant $subject,
        int $sourceWarehouseId,
        int $targetWarehouseId,
        float $quantity,
        ?string $note = null,
        ?int $createdBy = null,
    ): array {
        return DB::transaction(function () use ($subject, $sourceWarehouseId, $targetWarehouseId, $quantity, $note, $createdBy): array {
            if ($sourceWarehouseId === $targetWarehouseId) {
                throw new RuntimeException('Source and target warehouses must be different.');
            }

            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity must be greater than zero.');
            }

            [$product, $variant] = $this->resolveContext($subject);
            $source = $this->lockedBalance($product, $sourceWarehouseId, $variant?->id);
            $target = $this->lockedBalance($product, $targetWarehouseId, $variant?->id);

            if (((float) $source->quantity - (float) $source->reserved_quantity) + 0.001 < $quantity) {
                throw new RuntimeException('Insufficient available stock in source warehouse.');
            }

            $sourceAfter = (float) $source->quantity - $quantity;
            $targetAfter = (float) $target->quantity + $quantity;

            $this->assertQuantityIsAllowed($sourceAfter, (float) $source->reserved_quantity);
            $this->assertQuantityIsAllowed($targetAfter, (float) $target->reserved_quantity);

            $source->forceFill(['quantity' => $sourceAfter])->saveQuietly();
            $target->forceFill(['quantity' => $targetAfter])->saveQuietly();

            $this->syncProductStockIfDefaultWarehouse($product, $variant, $sourceWarehouseId, $sourceAfter);
            $this->syncProductStockIfDefaultWarehouse($product, $variant, $targetWarehouseId, $targetAfter);

            $movementOut = StockMovement::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'warehouse_id' => $sourceWarehouseId,
                'type' => StockMovement::TYPE_TRANSFER_OUT,
                'quantity' => -1 * $quantity,
                'balance_after' => $sourceAfter,
                'note' => $note,
                'created_by' => $createdBy,
            ]);

            $movementIn = StockMovement::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
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

    private function lockedBalance(Product $product, int $warehouseId, ?int $variantId = null): StockBalance
    {
        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->when($variantId, fn ($query, int $variantId) => $query->where('product_variant_id', $variantId))
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        if ($variantId) {
            $legacyBalance = StockBalance::query()
                ->where('product_id', $product->id)
                ->whereNull('product_variant_id')
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($legacyBalance) {
                $legacyBalance->forceFill(['product_variant_id' => $variantId])->saveQuietly();

                return $legacyBalance->refresh();
            }
        }

        $settings = CommerceSetting::current();
        $initialQuantity = (int) $settings->default_warehouse_id === $warehouseId
            ? (float) $product->stock
            : 0.0;

        $balance = new StockBalance([
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
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

    private function syncProductStockIfDefaultWarehouse(Product $product, ?ProductVariant $variant, int $warehouseId, float $quantity): void
    {
        $settings = CommerceSetting::current();

        if ((int) $settings->default_warehouse_id !== $warehouseId) {
            return;
        }

        if ($variant && ! $variant->is_default) {
            return;
        }

        $product->forceFill(['stock' => max(0, (int) floor($quantity))])->saveQuietly();
    }

    /**
     * @return array{0: Product, 1: ProductVariant|null}
     */
    private function resolveContext(Product|ProductVariant $subject): array
    {
        if ($subject instanceof ProductVariant) {
            return [$subject->product, $subject];
        }

        return [$subject, $subject->resolveDefaultVariant()];
    }
}
