<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAddonSetting extends Model
{
    protected $fillable = [
        'addon_code',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(SystemAddon::class, 'addon_code', 'code');
    }
}
