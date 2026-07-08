<?php

namespace App\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use RuntimeException;

/**
 * Reconciles the local marketplace catalog with the Phase 1 addon lifecycle.
 *
 * It does NOT implement lifecycle logic itself: every install/enable/disable/
 * uninstall/discover is delegated to the existing AddonManager / AddonLifecycle
 * pipeline. The manager only computes a computed status per catalog item,
 * collects diagnostics, and decides which actions are safe to show.
 */
final class MarketplaceManager
{
    public function __construct(
        private readonly MarketplaceCatalog $catalog,
        private readonly AddonRegistry $registry,
        private readonly AddonManager $manager,
        private readonly AddonEventLogger $events,
    ) {}

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     diagnostics: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function resolve(): array
    {
        $catalog = $this->catalog->load();
        $rows = [];

        foreach ($catalog['items'] as $item) {
            $rows[] = $this->resolveItem($item);
        }

        return [
            'rows' => $rows,
            'diagnostics' => $catalog['diagnostics'],
            'warnings' => $catalog['warnings'],
        ];
    }

    /**
     * @return array{
     *     item: MarketplaceItem,
     *     addon: SystemAddon|null,
     *     status: string,
     *     warnings: array<int, string>,
     *     actions: array<int, string>,
     *     dependency_issues: array<int, string>
     * }
     */
    private function resolveItem(MarketplaceItem $item): array
    {
        $addon = $this->registry->find($item->code);
        $warnings = [];

        if ($item->path !== null && ! is_file(base_path($item->path))) {
            $warnings[] = "Файли маніфесту не знайдено за шляхом [{$item->path}].";
        }

        $status = $this->computeStatus($item, $addon);

        $dependencyIssues = $this->dependencyIssues($item);
        foreach ($dependencyIssues as $issue) {
            $warnings[] = "Залежність: {$issue}";
        }

        if ($addon !== null) {
            $this->logDependencyIssues($item->code, $dependencyIssues);
        }

        $actions = $this->availableActions($status, $item, $dependencyIssues);

        return [
            'item' => $item,
            'addon' => $addon,
            'status' => $status,
            'warnings' => $warnings,
            'actions' => $actions,
            'dependency_issues' => $dependencyIssues,
        ];
    }

    private function computeStatus(MarketplaceItem $item, ?SystemAddon $addon): string
    {
        if (! $item->isValid()) {
            return MarketplaceStatus::INVALID;
        }

        if ($addon === null) {
            if ($item->path !== null && ! is_file(base_path($item->path))) {
                return MarketplaceStatus::MISSING_FILES;
            }

            return MarketplaceStatus::AVAILABLE;
        }

        if ($addon->manifest_path && ! is_file(base_path($addon->manifest_path))) {
            return MarketplaceStatus::MISSING_FILES;
        }

        return match ($addon->status) {
            SystemAddon::STATUS_DISCOVERED => MarketplaceStatus::DISCOVERED,
            SystemAddon::STATUS_INSTALLED => MarketplaceStatus::INSTALLED,
            SystemAddon::STATUS_ENABLED => MarketplaceStatus::ENABLED,
            SystemAddon::STATUS_DISABLED => MarketplaceStatus::DISABLED,
            SystemAddon::STATUS_FAILED => MarketplaceStatus::FAILED,
            SystemAddon::STATUS_REMOVED => MarketplaceStatus::REMOVED,
            default => $addon->status,
        };
    }

    /**
     * @param  array<int, string>  $dependencyIssues
     * @return array<int, string>
     */
    private function availableActions(string $status, MarketplaceItem $item, array $dependencyIssues): array
    {
        if ($status === MarketplaceStatus::INVALID) {
            return [];
        }

        return match ($status) {
            MarketplaceStatus::AVAILABLE, MarketplaceStatus::MISSING_FILES => ['discover'],
            MarketplaceStatus::DISCOVERED => ['install'],
            MarketplaceStatus::INSTALLED, MarketplaceStatus::DISABLED => ['enable', 'uninstall'],
            MarketplaceStatus::ENABLED => ['disable', 'uninstall'],
            MarketplaceStatus::FAILED => ['install', 'uninstall'],
            MarketplaceStatus::REMOVED => ['discover'],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function dependencyIssues(MarketplaceItem $item): array
    {
        $issues = [];

        foreach ($item->dependencies as $dependencyCode) {
            $dependency = $this->registry->find($dependencyCode);

            if (! $dependency || ! $dependency->is_installed) {
                $issues[] = "Залежність [{$dependencyCode}] не встановлено.";

                continue;
            }

            if (! $dependency->is_enabled) {
                $issues[] = "Залежність [{$dependencyCode}] вимкнено.";
            }
        }

        return $issues;
    }

    /**
     * @return array{discovered: int, invalid: int, duplicates: int}
     */
    public function discover(): array
    {
        return $this->manager->discover();
    }

    public function install(string $code): SystemAddon
    {
        return $this->manager->install($code);
    }

    public function enable(string $code): SystemAddon
    {
        $item = $this->findItem($code);

        if ($item !== null) {
            $issues = $this->dependencyIssues($item);

            if ($issues !== []) {
                $message = 'Неможливо увімкнути: невиконані залежності — '.implode(' ', $issues);

                if ($this->registry->find($code) !== null) {
                    $this->events->error($code, 'marketplace_enable_blocked', $message, $issues);
                }

                throw new RuntimeException($message);
            }
        }

        return $this->manager->enable($code);
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function logDependencyIssues(string $code, array $issues): void
    {
        if ($issues === []) {
            return;
        }

        foreach ($issues as $issue) {
            if (str_contains($issue, 'вимкнено')) {
                $this->events->warning($code, 'marketplace_dependency_disabled', 'Marketplace dependency is not enabled.', [
                    'issue' => $issue,
                ]);
            } else {
                $this->events->warning($code, 'marketplace_dependency_missing', 'Marketplace dependency is not installed.', [
                    'issue' => $issue,
                ]);
            }
        }
    }

    public function disable(string $code): SystemAddon
    {
        return $this->manager->disable($code);
    }

    public function uninstall(string $code): SystemAddon
    {
        return $this->manager->uninstall($code);
    }

    private function findItem(string $code): ?MarketplaceItem
    {
        foreach ($this->catalog->load()['items'] as $item) {
            if ($item->code === $code) {
                return $item;
            }
        }

        return null;
    }
}
