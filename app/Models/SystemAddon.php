<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemAddon extends Model
{
    public const TYPE_MODULE = 'module';

    public const TYPE_EXTENSION = 'extension';

    public const TYPES = [
        self::TYPE_MODULE => 'Module',
        self::TYPE_EXTENSION => 'Extension',
    ];

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_MARKETPLACE = 'marketplace';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_LOCAL => 'Local',
        self::SOURCE_MARKETPLACE => 'Marketplace',
        self::SOURCE_MANUAL => 'Manual',
    ];

    public const STATUS_DISCOVERED = 'discovered';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REMOVED = 'removed';

    public const STATUSES = [
        self::STATUS_DISCOVERED => 'Discovered',
        self::STATUS_INSTALLED => 'Installed',
        self::STATUS_ENABLED => 'Enabled',
        self::STATUS_DISABLED => 'Disabled',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_REMOVED => 'Removed',
    ];

    protected $fillable = [
        'code',
        'type',
        'name',
        'description',
        'vendor',
        'version',
        'source',
        'status',
        'is_installed',
        'is_enabled',
        'installed_at',
        'enabled_at',
        'disabled_at',
        'removed_at',
        'manifest_path',
        'service_provider',
        'checksum',
        'metadata',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_installed' => 'boolean',
            'is_enabled' => 'boolean',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'removed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function settings(): HasMany
    {
        return $this->hasMany(SystemAddonSetting::class, 'addon_code', 'code');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SystemAddonEvent::class, 'addon_code', 'code')->latest('created_at');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query
            ->where('is_enabled', true)
            ->where('status', self::STATUS_ENABLED);
    }

    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('is_installed', true);
    }
}
