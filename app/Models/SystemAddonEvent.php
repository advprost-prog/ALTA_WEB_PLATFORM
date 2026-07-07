<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAddonEvent extends Model
{
    public const UPDATED_AT = null;

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    public const LEVELS = [
        self::LEVEL_INFO => 'Info',
        self::LEVEL_WARNING => 'Warning',
        self::LEVEL_ERROR => 'Error',
    ];

    protected $fillable = [
        'addon_code',
        'event',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(SystemAddon::class, 'addon_code', 'code');
    }
}
