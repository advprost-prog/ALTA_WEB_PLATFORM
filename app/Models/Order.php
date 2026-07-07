<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'currency_id',
        'currency_code',
        'exchange_rate_to_base',
        'warehouse_id',
        'number',
        'customer_name',
        'phone',
        'email',
        'city',
        'address',
        'total_amount',
        'status',
        'payment_status',
        'delivery_status',
        'delivery_method',
        'delivery_method_id',
        'delivery_method_name',
        'payment_method',
        'payment_method_id',
        'payment_method_name',
        'customer_comment',
        'manager_comment',
        'confirmed_at',
        'paid_at',
        'shipped_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'exchange_rate_to_base' => 'decimal:6',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $settings = CommerceSetting::current();

            if (! $order->currency_id && $settings->default_currency_id) {
                $order->currency_id = $settings->default_currency_id;
            }

            if (! $order->warehouse_id && $settings->default_warehouse_id) {
                $order->warehouse_id = $settings->default_warehouse_id;
            }

            if ($order->currency_id && (! $order->currency_code || $order->exchange_rate_to_base === null)) {
                $currency = Currency::query()->find($order->currency_id);

                $order->currency_code ??= $currency?->code;
                $order->exchange_rate_to_base ??= $currency?->rate_to_base;
            }

            if (! $order->number) {
                $nextId = DB::table($order->getTable())->max('id') + 1;
                $order->number = 'AT-'.now()->format('ymd').'-'.str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
            }

            $order->status ??= OrderStatus::New->value;
            $order->payment_status ??= PaymentStatus::Unpaid->value;
            $order->delivery_status ??= DeliveryStatus::Pending->value;

            if ($order->payment_method_id && (! $order->payment_method || ! $order->payment_method_name)) {
                $paymentMethod = PaymentMethod::query()->find($order->payment_method_id);

                $order->payment_method ??= $paymentMethod?->code;
                $order->payment_method_name ??= $paymentMethod?->name;
            }

            if ($order->delivery_method_id && (! $order->delivery_method || ! $order->delivery_method_name)) {
                $deliveryMethod = DeliveryMethod::query()->find($order->delivery_method_id);

                $order->delivery_method ??= $deliveryMethod?->code;
                $order->delivery_method_name ??= $deliveryMethod?->name;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(bool $includeLegacy = true): array
    {
        return OrderStatus::options($includeLegacy);
    }

    /**
     * @return array<string, string>
     */
    public static function paymentStatusOptions(): array
    {
        return PaymentStatus::options();
    }

    /**
     * @return array<string, string>
     */
    public static function deliveryStatusOptions(): array
    {
        return DeliveryStatus::options();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationOutbox::class)->latest();
    }
}
