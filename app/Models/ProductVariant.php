<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'barcode',
        'base_unit_id',
        'sales_unit_id',
        'purchase_unit_id',
        'tax_profile_id',
        'is_excise_applicable',
        'excise_rate',
        'requires_excise_stamp_entry',
        'weight',
        'length',
        'width',
        'height',
        'is_default',
        'is_active',
        'sort_order',
        'external_source',
        'external_id',
        'external_code',
    ];

    protected $appends = [
        'display_name',
    ];

    protected function casts(): array
    {
        return [
            'is_excise_applicable' => 'boolean',
            'excise_rate' => 'decimal:2',
            'requires_excise_stamp_entry' => 'boolean',
            'weight' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            if ($variant->sales_unit_id === null) {
                $variant->sales_unit_id = $variant->base_unit_id;
            }

            if ($variant->purchase_unit_id === null) {
                $variant->purchase_unit_id = $variant->base_unit_id;
            }

            if ($variant->is_excise_applicable) {
                if ($variant->excise_rate === null || (float) $variant->excise_rate <= 0) {
                    $variant->excise_rate = 5.00;
                }

                return;
            }

            $variant->excise_rate = null;
            $variant->requires_excise_stamp_entry = false;
        });

        static::saved(function (self $variant): void {
            if ($variant->is_default) {
                self::query()
                    ->where('product_id', $variant->product_id)
                    ->whereKeyNot($variant->getKey())
                    ->update(['is_default' => false]);
            }

            $variant->product?->syncLegacyFieldsFromVariant($variant);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function salesUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sales_unit_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(VariantPackage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_main')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->product?->name,
            $this->name,
            $this->sku ? '['.$this->sku.']' : null,
        ]))) ?: ('Variant #'.$this->getKey());
    }
}
