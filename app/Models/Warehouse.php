<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'code',
        'address',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $warehouse): void {
            if (! $warehouse->is_default) {
                return;
            }

            self::query()
                ->whereKeyNot($warehouse->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });

        static::deleting(function (self $warehouse): void {
            if (CommerceSetting::query()->where('default_warehouse_id', $warehouse->getKey())->exists()) {
                throw new LogicException('Default commerce warehouse cannot be deleted while it is used in settings.');
            }

            if ($warehouse->stockBalances()->exists()) {
                throw new LogicException('Warehouse cannot be deleted while it is used by stock balances.');
            }

            if (StockMovement::query()->where('warehouse_id', $warehouse->getKey())->exists()) {
                throw new LogicException('Warehouse cannot be deleted while it is used by stock movements.');
            }

            if (Order::query()->where('warehouse_id', $warehouse->getKey())->exists()) {
                throw new LogicException('Warehouse cannot be deleted while it is used by orders.');
            }

            if (OrderItem::query()->where('warehouse_id', $warehouse->getKey())->exists()) {
                throw new LogicException('Warehouse cannot be deleted while it is used by order items.');
            }
        });
    }

    public static function ensureDefault(): self
    {
        $warehouse = self::query()->firstOrCreate(
            ['code' => 'main'],
            [
                'name' => 'Основний склад',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        if (! $warehouse->is_default || ! $warehouse->is_active) {
            $warehouse->forceFill([
                'name' => $warehouse->name ?: 'Основний склад',
                'is_default' => true,
                'is_active' => true,
            ])->save();
        }

        return $warehouse->refresh();
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
