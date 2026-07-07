<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public const TYPE_INITIAL = 'initial';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_SALE = 'sale';
    public const TYPE_RETURN = 'return';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPES = [
        self::TYPE_INITIAL => 'Початковий залишок',
        self::TYPE_ADJUSTMENT => 'Коригування',
        self::TYPE_SALE => 'Продаж',
        self::TYPE_RETURN => 'Повернення',
        self::TYPE_TRANSFER_IN => 'Переміщення: прихід',
        self::TYPE_TRANSFER_OUT => 'Переміщення: витрата',
    ];

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'type',
        'quantity',
        'balance_after',
        'related_type',
        'related_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
        ];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
