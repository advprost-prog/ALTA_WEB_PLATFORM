<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    public const TYPE_STATUS = 'status';

    public const TYPE_PAYMENT_STATUS = 'payment_status';

    public const TYPE_DELIVERY_STATUS = 'delivery_status';

    public const TYPE_NOTE = 'note';

    public const TYPE_SYSTEM = 'system';

    public const TYPES = [
        self::TYPE_STATUS => 'Статус замовлення',
        self::TYPE_PAYMENT_STATUS => 'Статус оплати',
        self::TYPE_DELIVERY_STATUS => 'Статус доставки',
        self::TYPE_NOTE => 'Нотатка',
        self::TYPE_SYSTEM => 'Системна подія',
    ];

    protected $fillable = [
        'order_id',
        'type',
        'from_value',
        'to_value',
        'comment',
        'created_by',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
