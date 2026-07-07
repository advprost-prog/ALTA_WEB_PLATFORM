<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockBalance extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'quantity',
        'reserved_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $balance): void {
            if ((float) $balance->quantity < 0) {
                throw new LogicException('Stock balance quantity cannot be negative.');
            }

            if ((float) $balance->reserved_quantity < 0) {
                throw new LogicException('Reserved stock quantity cannot be negative.');
            }

            if ((float) $balance->reserved_quantity > (float) $balance->quantity) {
                throw new LogicException('Reserved stock quantity cannot exceed total quantity.');
            }
        });

        static::saved(function (self $balance): void {
            $settings = CommerceSetting::query()->first();

            if ($settings
                && (int) $settings->default_warehouse_id === (int) $balance->warehouse_id
                && ($balance->variant === null || $balance->variant->is_default)) {
                $balance->product?->forceFill([
                    'stock' => max(0, (int) floor((float) $balance->quantity)),
                ])->saveQuietly();
            }

            if ($balance->wasRecentlyCreated) {
                $quantityDelta = (float) $balance->quantity;
                $type = StockMovement::TYPE_INITIAL;
            } elseif ($balance->wasChanged('quantity')) {
                $quantityDelta = (float) $balance->quantity - (float) $balance->getOriginal('quantity');
                $type = StockMovement::TYPE_ADJUSTMENT;
            } else {
                return;
            }

            if (abs($quantityDelta) < 0.001) {
                return;
            }

            StockMovement::query()->create([
                'product_id' => $balance->product_id,
                'product_variant_id' => $balance->product_variant_id,
                'warehouse_id' => $balance->warehouse_id,
                'type' => $type,
                'quantity' => $quantityDelta,
                'balance_after' => $balance->quantity,
                'note' => $type === StockMovement::TYPE_ADJUSTMENT ? 'Ручне коригування з картки товару' : null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    protected function availableQuantity(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->quantity - (float) $this->reserved_quantity);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
