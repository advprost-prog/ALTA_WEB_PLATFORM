<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'precision',
        'rate_to_base',
        'is_base',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'precision' => 'integer',
            'rate_to_base' => 'decimal:6',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $currency): void {
            $currency->code = mb_strtoupper($currency->code);
        });

        static::saved(function (self $currency): void {
            if (! $currency->is_base) {
                return;
            }

            self::query()
                ->whereKeyNot($currency->getKey())
                ->where('is_base', true)
                ->update(['is_base' => false]);
        });

        static::deleting(function (self $currency): void {
            if (CommerceSetting::query()->where('default_currency_id', $currency->getKey())->exists()) {
                throw new LogicException('Default commerce currency cannot be deleted while it is used in settings.');
            }

            if ($currency->productPrices()->exists()) {
                throw new LogicException('Currency cannot be deleted while it is used by product prices.');
            }

            if (Order::query()->where('currency_id', $currency->getKey())->exists()) {
                throw new LogicException('Currency cannot be deleted while it is used by orders.');
            }
        });
    }

    public static function ensureDefault(): self
    {
        $currency = self::query()->firstOrCreate(
            ['code' => 'UAH'],
            [
                'name' => 'Українська гривня',
                'symbol' => '₴',
                'precision' => 2,
                'rate_to_base' => '1.000000',
                'is_base' => true,
                'is_active' => true,
            ],
        );

        if (! $currency->is_base || ! $currency->is_active) {
            $currency->forceFill([
                'is_base' => true,
                'is_active' => true,
                'rate_to_base' => $currency->rate_to_base ?? '1.000000',
            ])->save();
        }

        return $currency->refresh();
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
