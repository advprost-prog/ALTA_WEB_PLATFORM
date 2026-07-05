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
        'warehouse_id',
        'product_name',
        'sku',
        'quantity',
        'unit_price',
        'price',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
