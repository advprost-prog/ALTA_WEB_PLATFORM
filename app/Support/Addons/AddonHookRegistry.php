<?php

namespace App\Support\Addons;

use Closure;
use Throwable;

class AddonHookRegistry
{
    /**
     * @var array<string, array<int, array{handler: mixed, priority: int, addon_code: string|null}>>
     */
    private array $hooks = [];

    public const KNOWN_HOOKS = [
        'admin.dashboard.widgets',
        'admin.navigation.items',
        'storefront.home.blocks',
        'storefront.product.card.badges',
        'storefront.product.detail.sections',
        'catalog.product.saved',
        'order.created',
        'banner.render.before',
        'banner.render.after',
    ];

    public function register(string $hookName, mixed $handler, int $priority = 0, ?string $addonCode = null): void
    {
        $this->hooks[$hookName][] = [
            'handler' => $handler,
            'priority' => $priority,
            'addon_code' => $addonCode,
        ];

        usort($this->hooks[$hookName], fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
    }

    /**
     * @return array<int, array{handler: mixed, priority: int, addon_code: string|null}>
     */
    public function get(string $hookName): array
    {
        return $this->hooks[$hookName] ?? [];
    }

    public function flushAddon(string $addonCode): void
    {
        foreach ($this->hooks as $hookName => $handlers) {
            $this->hooks[$hookName] = array_values(array_filter(
                $handlers,
                fn (array $handler): bool => $handler['addon_code'] !== $addonCode,
            ));
        }
    }

    /**
     * @return array<int, mixed>
     */
    public function run(string $hookName, mixed $payload = null): array
    {
        $results = [];

        foreach ($this->get($hookName) as $hook) {
            $callable = $this->toCallable($hook['handler']);

            if (! $callable) {
                continue;
            }

            try {
                $results[] = $callable($payload);
            } catch (Throwable) {
                continue;
            }
        }

        return $results;
    }

    public function filter(string $hookName, mixed $payload): mixed
    {
        foreach ($this->get($hookName) as $hook) {
            $callable = $this->toCallable($hook['handler']);

            if (! $callable) {
                continue;
            }

            try {
                $payload = $callable($payload);
            } catch (Throwable) {
                continue;
            }
        }

        return $payload;
    }

    private function toCallable(mixed $handler): ?Closure
    {
        if (is_callable($handler)) {
            return Closure::fromCallable($handler);
        }

        if (is_string($handler) && class_exists($handler) && is_callable(app($handler))) {
            return Closure::fromCallable(app($handler));
        }

        return null;
    }
}
