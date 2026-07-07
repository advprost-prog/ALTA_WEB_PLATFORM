<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxProfile extends Model
{
    protected $fillable = [
        'name',
        'code',
        'vat_rate',
        'price_includes_tax',
        'fiscal_group_code',
        'description',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'price_includes_tax' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if (! $profile->is_default) {
                return;
            }

            self::query()
                ->whereKeyNot($profile->getKey())
                ->update(['is_default' => false]);
        });
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'default_tax_profile_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function ensureDefault(): self
    {
        return self::query()->where('is_default', true)->first()
            ?? self::query()->firstWhere('code', 'no_vat')
            ?? self::query()->create([
                'name' => 'Без ПДВ',
                'code' => 'no_vat',
                'vat_rate' => 0,
                'price_includes_tax' => true,
                'description' => 'Базовий профіль без ПДВ',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 10,
            ]);
    }
}