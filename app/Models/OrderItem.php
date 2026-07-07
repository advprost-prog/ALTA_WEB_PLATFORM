<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'product_name',
        'sku',
        'unit_name',
        'unit_short_name',
        'base_unit_id',
        'sales_unit_id',
        'quantity',
        'quantity_in_base_unit',
        'tax_profile_id',
        'tax_profile_name',
        'tax_profile_code',
        'vat_rate',
        'vat_amount',
        'is_excise_applicable',
        'excise_rate',
        'excise_amount',
        'requires_excise_stamp_entry',
        'unit_price',
        'price_excluding_tax',
        'price_including_tax',
        'price',
        'total',
        'line_total_excluding_tax',
        'line_total_tax_amount',
        'line_total_including_tax',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_in_base_unit' => 'decimal:3',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'is_excise_applicable' => 'boolean',
            'excise_rate' => 'decimal:2',
            'excise_amount' => 'decimal:2',
            'requires_excise_stamp_entry' => 'boolean',
            'unit_price' => 'decimal:2',
            'price_excluding_tax' => 'decimal:2',
            'price_including_tax' => 'decimal:2',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'line_total_excluding_tax' => 'decimal:2',
            'line_total_tax_amount' => 'decimal:2',
            'line_total_including_tax' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->unit_price === null && $item->price !== null) {
                $item->unit_price = $item->price;
            }

            if ($item->price === null && $item->unit_price !== null) {
                $item->price = $item->unit_price;
            }

            if ($item->total === null && $item->price !== null) {
                $item->total = (float) $item->price * max(1, (int) $item->quantity);
            }

            $item->quantity_in_base_unit ??= $item->quantity;
            $item->price_excluding_tax ??= $item->unit_price ?? $item->price;
            $item->price_including_tax ??= $item->price ?? $item->unit_price;
            $item->line_total_excluding_tax ??= $item->total;
            $item->line_total_tax_amount ??= 0;
            $item->line_total_including_tax ??= $item->total;

            if ($item->warehouse_id) {
                return;
            }

            $order = $item->order_id ? Order::query()->find($item->order_id) : null;
            $item->warehouse_id = $order?->warehouse_id ?? CommerceSetting::current()->default_warehouse_id;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function salesUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sales_unit_id');
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
