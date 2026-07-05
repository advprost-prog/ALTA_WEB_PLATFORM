<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'value',
        'type',
        'group',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public static function value(string $key, ?string $default = null): ?string
    {
        return cache()->rememberForever(
            "site_setting:{$key}",
            fn (): ?string => self::query()->where('key', $key)->value('value') ?? $default,
        );
    }
}
