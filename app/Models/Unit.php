<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = [
        'name',
        'short_name',
        'code',
        'international_code',
        'type',
        'precision',
        'is_fractional',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'precision' => 'integer',
            'is_fractional' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function baseVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'base_unit_id');
    }

    public function salesVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'sales_unit_id');
    }

    public function purchaseVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'purchase_unit_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function ensurePiece(): self
    {
        return self::query()->firstWhere('code', 'piece')
            ?? self::query()->create([
                'name' => 'Штука',
                'short_name' => 'шт',
                'code' => 'piece',
                'international_code' => 'piece',
                'type' => 'count',
                'precision' => 0,
                'is_fractional' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]);
    }
}