<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'currency_id',
        'price',
        'compare_at_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $price): void {
            $settings = CommerceSetting::query()->first();

            if (! $settings || (int) $settings->default_currency_id !== (int) $price->currency_id) {
                return;
            }

             if ($price->variant && ! $price->variant->is_default) {
                return;
            }

            $price->product?->forceFill([
                'price' => $price->price,
                'old_price' => $price->compare_at_price,
            ])->saveQuietly();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
