<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VariantPackage extends Model
{
    protected $fillable = [
        'product_variant_id',
        'unit_id',
        'name',
        'quantity_in_base_unit',
        'barcode',
        'is_default_sales_package',
        'weight',
        'length',
        'width',
        'height',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_in_base_unit' => 'decimal:3',
            'is_default_sales_package' => 'boolean',
            'weight' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            if ((float) $package->quantity_in_base_unit <= 0) {
                throw new LogicException('Package quantity must be greater than zero.');
            }
        });

        static::saved(function (self $package): void {
            if (! $package->is_default_sales_package) {
                return;
            }

            self::query()
                ->where('product_variant_id', $package->product_variant_id)
                ->whereKeyNot($package->getKey())
                ->update(['is_default_sales_package' => false]);
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}