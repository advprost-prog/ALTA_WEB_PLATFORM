<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Throwable;

class AddonManager
{
    public function __construct(
        public readonly AddonDiscovery $discovery,
        public readonly AddonRegistry $registry,
        public readonly AddonLifecycle $lifecycle,
        public readonly AddonHookRegistry $hooks,
        private readonly AddonEventLogger $events,
    ) {}

    /**
     * @return array{discovered: int, invalid: int, duplicates: int}
     */
    public function discover(): array
    {
        return $this->discovery->sync();
    }

    public function install(string $code): SystemAddon
    {
        return $this->lifecycle->install($code);
    }

    public function enable(string $code): SystemAddon
    {
        $addon = $this->lifecycle->enable($code);
        $this->bootAddon($addon);

        return $addon;
    }

    public function disable(string $code): SystemAddon
    {
        $addon = $this->lifecycle->disable($code);
        $this->hooks->flushAddon($addon->code);

        return $addon;
    }

    public function uninstall(string $code): SystemAddon
    {
        $addon = $this->lifecycle->uninstall($code);
        $this->hooks->flushAddon($addon->code);

        return $addon;
    }

    public function remove(string $code): SystemAddon
    {
        $addon = $this->lifecycle->remove($code);
        $this->hooks->flushAddon($addon->code);

        return $addon;
    }

    public function bootEnabledAddons(): void
    {
        foreach ($this->registry->enabled() as $addon) {
            try {
                $this->bootAddon($addon);
            } catch (Throwable $exception) {
                $this->markRuntimeFailure($addon, 'Addon boot failed: '.$exception->getMessage(), 'boot_failed', [
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function bootAddon(SystemAddon $addon): void
    {
        if (! $addon->is_enabled || $addon->status !== SystemAddon::STATUS_ENABLED) {
            return;
        }

        if (! $addon->manifest_path || ! is_file(base_path($addon->manifest_path))) {
            $this->markRuntimeFailure($addon, 'Manifest not found', 'manifest_missing', [
                'manifest_path' => $addon->manifest_path,
            ]);

            return;
        }

        $manifest = $addon->metadata['manifest'] ?? [];

        if (! is_array($manifest)) {
            $this->markRuntimeFailure($addon, 'Manifest metadata is invalid', 'manifest_invalid', [
                'manifest_path' => $addon->manifest_path,
            ]);

            return;
        }

        $this->hooks->flushAddon($addon->code);

        if (! $this->registerServiceProvider($addon)) {
            return;
        }

        $this->registerViews($addon);
        $this->registerRoutes($addon, $manifest);
        $this->registerManifestHooks($addon, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function registerManifestHooks(SystemAddon $addon, array $manifest): void
    {
        $hooks = is_array($manifest['hooks'] ?? null) ? $manifest['hooks'] : [];

        foreach ($hooks as $hook) {
            if (! is_array($hook) || ! is_string($hook['name'] ?? null) || ! is_string($hook['handler'] ?? null)) {
                continue;
            }

            if (! $this->hookHandlerIsAllowed($addon, $hook['handler'])) {
                $this->events->warning($addon->code, 'hook_skipped', 'Hook handler is outside the allowed local addon namespace.', [
                    'hook' => $hook['name'],
                    'handler' => $hook['handler'],
                ]);

                continue;
            }

            $this->hooks->register(
                $hook['name'],
                $hook['handler'],
                (int) ($hook['priority'] ?? 0),
                $addon->code,
            );
        }
    }

    private function registerViews(SystemAddon $addon): void
    {
        $viewsPath = dirname(base_path((string) $addon->manifest_path)).'/resources/views';

        if (is_dir($viewsPath)) {
            View::addNamespace($addon->code, $viewsPath);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function registerRoutes(SystemAddon $addon, array $manifest): void
    {
        $routes = is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [];
        $baseDirectory = dirname(base_path((string) $addon->manifest_path));

        foreach ($routes as $name => $relativePath) {
            if (! is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $path = $baseDirectory.'/'.ltrim($relativePath, '/');

            if (! $this->isLocalAddonFile($path)) {
                $this->events->warning($addon->code, 'route_skipped', 'Route file is missing or outside addon directory.', [
                    'route' => (string) $name,
                    'path' => $relativePath,
                ]);

                continue;
            }

            if ($name === 'admin') {
                Route::middleware(['web', FilamentAuthenticate::class])->prefix('admin')->group($path);

                continue;
            }

            Route::middleware(['web'])->group($path);
        }
    }

    private function registerServiceProvider(SystemAddon $addon): bool
    {
        if (! $addon->service_provider) {
            return true;
        }

        if (! $this->lifecycle->serviceProviderIsAllowed($addon)) {
            $this->markRuntimeFailure($addon, 'Service provider is outside the allowed local addon namespace/path.', 'service_provider_blocked', [
                'service_provider' => $addon->service_provider,
            ]);

            return false;
        }

        if (! $this->lifecycle->serviceProviderClassExists($addon)) {
            $this->markRuntimeFailure($addon, 'Service provider class not found: '.$addon->service_provider, 'service_provider_missing', [
                'service_provider' => $addon->service_provider,
            ]);

            return false;
        }

        try {
            app()->register($addon->service_provider);
            $this->events->info($addon->code, 'service_provider_registered', 'Addon service provider registered.', [
                'service_provider' => $addon->service_provider,
            ]);
        } catch (Throwable $exception) {
            $this->markRuntimeFailure($addon, 'Addon boot failed: '.$exception->getMessage(), 'service_provider_failed', [
                'service_provider' => $addon->service_provider,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function isLocalAddonFile(string $path): bool
    {
        $realPath = realpath($path);
        $basePath = realpath(base_path());

        return $realPath !== false
            && $basePath !== false
            && str_starts_with($realPath, $basePath)
            && is_file($realPath);
    }

    private function hookHandlerIsAllowed(SystemAddon $addon, string $handler): bool
    {
        return str_starts_with($handler, $this->expectedAddonNamespace($addon));
    }

    private function expectedAddonNamespace(SystemAddon $addon): string
    {
        $directory = dirname(base_path((string) $addon->manifest_path));
        $relative = trim(str_replace('\\', '/', str_replace(base_path(), '', $directory)), '/');
        $parts = array_slice(explode('/', $relative), 1);

        return ($addon->type === SystemAddon::TYPE_MODULE ? 'Modules' : 'Extensions')
            .'\\'.implode('\\', $parts).'\\';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function markRuntimeFailure(SystemAddon $addon, string $message, string $event, array $context = []): void
    {
        $this->hooks->flushAddon($addon->code);
        $this->lifecycle->markRuntimeFailure($addon, $message, $event, $context);
    }
}
