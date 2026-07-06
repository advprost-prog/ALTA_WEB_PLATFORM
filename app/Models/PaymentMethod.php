<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    public const CASH_ON_DELIVERY = 'cash_on_delivery';

    public const BANK_TRANSFER = 'bank_transfer';

    public const CASH = 'cash';

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'code' => self::CASH_ON_DELIVERY,
                'name' => 'Післяплата',
                'description' => 'Оплата при отриманні замовлення.',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => self::BANK_TRANSFER,
                'name' => 'Банківський переказ',
                'description' => 'Оплата за рахунком після підтвердження менеджером.',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => self::CASH,
                'name' => 'Готівка',
                'description' => 'Оплата готівкою в магазині.',
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::defaults() as $method) {
            self::query()->updateOrCreate(
                ['code' => $method['code']],
                $method,
            );
        }
    }
}
