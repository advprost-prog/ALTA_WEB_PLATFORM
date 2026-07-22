<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Schema;

final class FilamentAddonComponents
{
    public function __construct(private readonly AddonLifecycle $lifecycle) {}

    /** @return array{pages: list<class-string<Page>>, resources: list<class-string<resource>>} */
    public function enabled(): array
    {
        $components = ['pages' => [], 'resources' => []];
        if (! Schema::hasTable('system_addons')) {
            return $components;
        }

        foreach (SystemAddon::query()->where('is_installed', true)->where('is_enabled', true)->where('status', SystemAddon::STATUS_ENABLED)->get() as $addon) {
            $filament = $addon->metadata['manifest']['filament'] ?? null;
            if (! is_array($filament) || ! $addon->service_provider || ! $this->lifecycle->serviceProviderClassExists($addon)) {
                continue;
            }
            foreach (['pages' => Page::class, 'resources' => Resource::class] as $type => $baseClass) {
                foreach ((array) ($filament[$type] ?? []) as $class) {
                    if (is_string($class) && $this->lifecycle->ownsRuntimeClass($addon, $class) && is_subclass_of($class, $baseClass)) {
                        $components[$type][$class] = $class;
                    }
                }
            }
        }

        return ['pages' => array_values($components['pages']), 'resources' => array_values($components['resources'])];
    }
}
