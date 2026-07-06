<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryMethod extends Model
{
    public const NOVA_POSHTA = 'nova_poshta';

    public const PICKUP = 'pickup';

    public const COURIER = 'courier';

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
                'code' => self::NOVA_POSHTA,
                'name' => 'Нова пошта',
                'description' => 'Відправка через відділення або поштомат Нової пошти.',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => self::PICKUP,
                'name' => 'Самовивіз',
                'description' => 'Отримання замовлення в магазині після підтвердження.',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => self::COURIER,
                'name' => 'Кур’єрська доставка',
                'description' => 'Адресна доставка кур’єром після узгодження з менеджером.',
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
