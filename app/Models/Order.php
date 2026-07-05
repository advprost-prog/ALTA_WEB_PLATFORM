<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    public const STATUSES = [
        'new' => 'Нове',
        'confirmed' => 'Підтверджене',
        'processing' => 'В роботі',
        'awaiting_payment' => 'Очікує оплати',
        'shipped' => 'Відправлено',
        'completed' => 'Виконано',
        'cancelled' => 'Скасовано',
    ];

    protected $fillable = [
        'customer_id',
        'number',
        'customer_name',
        'phone',
        'email',
        'total_amount',
        'status',
        'delivery_method',
        'payment_method',
        'customer_comment',
        'manager_comment',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if ($order->number) {
                return;
            }

            $nextId = DB::table($order->getTable())->max('id') + 1;
            $order->number = 'AT-' . now()->format('ymd') . '-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
