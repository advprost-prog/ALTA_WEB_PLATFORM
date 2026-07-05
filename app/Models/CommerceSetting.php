<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CommerceSetting extends Model
{
    protected $fillable = [
        'multi_currency_enabled',
        'multi_warehouse_enabled',
        'default_currency_id',
        'default_warehouse_id',
    ];

    protected function casts(): array
    {
        return [
            'multi_currency_enabled' => 'boolean',
            'multi_warehouse_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $settings): void {
            if (! $settings->default_currency_id) {
                $settings->default_currency_id = Currency::ensureDefault()->id;
            }

            if (! $settings->default_warehouse_id) {
                $settings->default_warehouse_id = Warehouse::ensureDefault()->id;
            }
        });

        static::creating(function (): void {
            if (self::query()->exists()) {
                throw new LogicException('Only one commerce settings record is supported.');
            }
        });
    }

    public static function current(): self
    {
        $settings = self::query()->orderBy('id')->first();

        if (! $settings) {
            return self::createDefault();
        }

        if (! $settings->default_currency_id || ! $settings->default_warehouse_id) {
            $settings->forceFill([
                'default_currency_id' => $settings->default_currency_id ?: Currency::ensureDefault()->id,
                'default_warehouse_id' => $settings->default_warehouse_id ?: Warehouse::ensureDefault()->id,
            ])->save();
        }

        return $settings->refresh();
    }

    public static function createDefault(): self
    {
        $currency = Currency::ensureDefault();
        $warehouse = Warehouse::ensureDefault();

        return self::query()->create([
            'multi_currency_enabled' => false,
            'multi_warehouse_enabled' => false,
            'default_currency_id' => $currency->id,
            'default_warehouse_id' => $warehouse->id,
        ]);
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}
