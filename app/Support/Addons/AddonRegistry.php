<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use App\Models\SystemAddonSetting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Schema;

class AddonRegistry
{
    /**
     * @return EloquentCollection<int, SystemAddon>
     */
    public function all(): EloquentCollection
    {
        if (! Schema::hasTable('system_addons')) {
            return new EloquentCollection;
        }

        return SystemAddon::query()->orderBy('type')->orderBy('code')->get();
    }

    /**
     * @return EloquentCollection<int, SystemAddon>
     */
    public function installed(): EloquentCollection
    {
        if (! Schema::hasTable('system_addons')) {
            return new EloquentCollection;
        }

        return SystemAddon::query()->installed()->orderBy('code')->get();
    }

    /**
     * @return EloquentCollection<int, SystemAddon>
     */
    public function enabled(): EloquentCollection
    {
        if (! Schema::hasTable('system_addons')) {
            return new EloquentCollection;
        }

        return SystemAddon::query()->enabled()->orderBy('code')->get();
    }

    public function find(string $code): ?SystemAddon
    {
        if (! Schema::hasTable('system_addons')) {
            return null;
        }

        return SystemAddon::query()->where('code', $code)->first();
    }

    public function isEnabled(string $code): bool
    {
        return (bool) $this->find($code)?->is_enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(string $addonCode): array
    {
        if (! Schema::hasTable('system_addon_settings')) {
            return [];
        }

        return SystemAddonSetting::query()
            ->where('addon_code', $addonCode)
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function setSetting(string $addonCode, string $key, array $value): SystemAddonSetting
    {
        return SystemAddonSetting::query()->updateOrCreate(
            [
                'addon_code' => $addonCode,
                'key' => $key,
            ],
            ['value' => $value],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function permissions(): array
    {
        return $this->enabled()
            ->flatMap(function (SystemAddon $addon): array {
                $manifest = $addon->metadata['manifest'] ?? [];
                $permissions = is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [];

                return array_map(
                    fn (array|string $permission): array => is_array($permission)
                        ? array_merge(['addon_code' => $addon->code], $permission)
                        : ['addon_code' => $addon->code, 'code' => $permission, 'label' => $permission],
                    $permissions,
                );
            })
            ->values()
            ->all();
    }
}
