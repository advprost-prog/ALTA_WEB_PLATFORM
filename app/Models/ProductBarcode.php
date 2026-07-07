<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    protected $fillable = [
        'product_variant_id',
        'variant_package_id',
        'barcode',
        'type',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $barcode): void {
            if (! $barcode->is_primary) {
                return;
            }

            self::query()
                ->where('product_variant_id', $barcode->product_variant_id)
                ->whereKeyNot($barcode->getKey())
                ->update(['is_primary' => false]);
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(VariantPackage::class, 'variant_package_id');
    }
}