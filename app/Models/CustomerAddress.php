<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_BILLING = 'billing';

    public const TYPE_PICKUP = 'pickup';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'customer_id',
        'type',
        'recipient_name',
        'recipient_phone',
        'city',
        'address',
        'postal_code',
        'delivery_method_id',
        'provider',
        'warehouse_ref',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            $address->type ??= self::TYPE_DELIVERY;
        });

        static::saved(function (self $address): void {
            if (! $address->is_default || ! $address->customer_id) {
                return;
            }

            self::query()
                ->where('customer_id', $address->customer_id)
                ->where('type', $address->type)
                ->whereKeyNot($address->id)
                ->update(['is_default' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_DELIVERY => 'Доставка',
            self::TYPE_BILLING => 'Рахунок',
            self::TYPE_PICKUP => 'Самовивіз',
            self::TYPE_OTHER => 'Інше',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }
}
