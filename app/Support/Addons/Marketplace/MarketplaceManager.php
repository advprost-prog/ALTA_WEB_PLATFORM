<?php

namespace App\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\PlatformVersion;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryItem;
use App\Support\Addons\Version\VersionComparator;
use RuntimeException;

final class MarketplaceManager
{
    public function __construct(
        private readonly MarketplaceCatalog $catalog,
        private readonly AddonRegistry $registry,
        private readonly AddonManager $manager,
        private readonly AddonEventLogger $events,
        private readonly DependencyResolver $resolver = new DependencyResolver,
        private readonly VersionComparator $versionComparator = new VersionComparator,
        private readonly PlatformVersion $platformVersion = new PlatformVersion,
        private readonly ?RegistryCatalog $registryCatalog = null,
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
        $remoteCatalog = $this->loadRemoteCatalog();
        $remoteItems = [];

        foreach ($remoteCatalog['items'] ?? [] as $item) {
            $remoteItems[$item->code] = $item;
        }

        foreach ($catalog['items'] as $item) {
            $remoteItem = $remoteItems[$item->code] ?? null;
            $row = $this->resolveItem($item, $remoteItem);
            $row['source'] = $remoteItem !== null ? 'local_remote' : 'local';
            $row['remote_version'] = $remoteItem?->version;
            $row['local_catalog_version'] = $item->version;
            $row['registry_metadata'] = $remoteItem?->raw ?? [];
            $rows[] = $row;
        }

        foreach ($remoteItems as $code => $remoteItem) {
            if (isset($remoteItems[$code]) && $this->catalog->load()['items']) {
                foreach ($this->catalog->load()['items'] as $localItem) {
                    if ($localItem->code === $code) {
                        continue 2;
                    }
                }
            }

            $row = $this->resolveRemoteOnlyItem($remoteItem);
            $row['source'] = 'remote';
            $row['remote_version'] = $remoteItem->version;
            $row['local_catalog_version'] = null;
            $row['registry_metadata'] = $remoteItem->raw;
            $rows[] = $row;
        }

        $diagnostics = array_merge($catalog['diagnostics'], $remoteCatalog['diagnostics'] ?? []);

        return [
            'rows' => $rows,
            'diagnostics' => $diagnostics,
            'warnings' => $catalog['warnings'],
        ];
    }

    /**
     * @return array{items: list<RegistryItem>, diagnostics: list<string>}
     */
    private function loadRemoteCatalog(): array
    {
        if ($this->registryCatalog === null) {
            return ['items' => [], 'diagnostics' => []];
        }

        return $this->registryCatalog->load();
    }

    /**
     * @return array{
     *     item: MarketplaceItem,
     *     addon: SystemAddon|null,
     *     status: string,
     *     installed_version: string|null,
     *     available_version: string|null,
     *     platform_constraint: string|null,
     *     dependency_constraints: array<string, string|null>,
     *     update_status: string,
     *     compatibility_status: string,
     *     warnings: array<int, string>,
     *     actions: array<int, string>,
     *     dependency_issues: array<int, string>,
     *     dependency_report: array<string, array<string, mixed>>,
     *     can_install_dependencies: bool,
     *     blocked_reasons: array<int, string>
     * }
     */
    private function resolveItem(MarketplaceItem $item, ?RegistryItem $remoteItem = null): array
    {
        $addon = $this->registry->find($item->code);
        $warnings = [];

        if ($item->path !== null && ! is_file(base_path($item->path))) {
            $warnings[] = "Файли маніфесту не знайдено за шляхом [{$item->path}].";
        }

        $status = $this->computeStatus($item, $addon);
        $compatibilityStatus = $this->compatibilityStatus($item);
        $updateStatus = $this->updateStatus($status, $addon, $item);

        $dependencyReport = $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );

        $dependencyIssues = [];
        foreach ($dependencyReport as $code => $report) {
            foreach ($report['issues'] as $issue) {
                $dependencyIssues[] = $issue;
            }
        }

        foreach ($dependencyIssues as $issue) {
            $warnings[] = "Залежність: {$issue}";
        }

        if ($addon !== null) {
            $this->logDependencyIssues($item->code, $dependencyIssues);
        }

        $actions = $this->availableActions($status, $item, $dependencyIssues, $updateStatus, $compatibilityStatus);
        $canInstallDependencies = $this->canInstallDependencies($item->code);
        $blockedReasons = $this->getBlockedReasons($item->code);

        return [
            'item' => $item,
            'addon' => $addon,
            'status' => $status,
            'installed_version' => $addon?->version,
            'available_version' => $item->version ?: null,
            'platform_constraint' => $item->getPlatformConstraint(),
            'dependency_constraints' => $item->getDependencyConstraints(),
            'update_status' => $updateStatus,
            'compatibility_status' => $compatibilityStatus,
            'warnings' => $warnings,
            'actions' => $actions,
            'dependency_issues' => $dependencyIssues,
            'dependency_report' => $dependencyReport,
            'can_install_dependencies' => $canInstallDependencies,
            'blocked_reasons' => $blockedReasons,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRemoteOnlyItem(RegistryItem $remoteItem): array
    {
        $item = MarketplaceItem::fromArray([
            'code' => $remoteItem->code,
            'type' => $remoteItem->type,
            'vendor' => $remoteItem->vendor,
            'name' => $remoteItem->name,
            'description' => $remoteItem->description,
            'version' => $remoteItem->version,
            'category' => $remoteItem->category,
            'tags' => $remoteItem->tags,
            'platform_version' => $remoteItem->platformConstraint,
            'dependencies' => $remoteItem->dependencies,
            'is_featured' => $remoteItem->isFeatured,
            'path' => null,
        ]);

        $status = MarketplaceStatus::REMOTE_ONLY;
        $compatibilityStatus = $this->compatibilityStatus($item);
        $updateStatus = UpdateStatus::NOT_INSTALLED;
        $dependencyIssues = [];
        $warnings = [];
        $actions = [];
        $dependencyReport = $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );

        foreach ($dependencyReport as $code => $report) {
            foreach ($report['issues'] as $issue) {
                $dependencyIssues[] = $issue;
                $warnings[] = "Залежність: {$issue}";
            }
        }

        $blockedReasons = $this->getBlockedReasons($item->code);
        $canInstallDependencies = $this->canInstallDependencies($item->code);

        return [
            'item' => $item,
            'addon' => null,
            'status' => $status,
            'installed_version' => null,
            'available_version' => $item->version ?: null,
            'platform_constraint' => $item->getPlatformConstraint(),
            'dependency_constraints' => $item->getDependencyConstraints(),
            'update_status' => $updateStatus,
            'compatibility_status' => $compatibilityStatus,
            'warnings' => $warnings,
            'actions' => $actions,
            'dependency_issues' => $dependencyIssues,
            'dependency_report' => $dependencyReport,
            'can_install_dependencies' => $canInstallDependencies,
            'blocked_reasons' => $blockedReasons,
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
    private function availableActions(string $status, MarketplaceItem $item, array $dependencyIssues, string $updateStatus, string $compatibilityStatus): array
    {
        if ($status === MarketplaceStatus::INVALID) {
            return [];
        }

        $incompatible = $compatibilityStatus === CompatibilityStatus::INCOMPATIBLE;

        if ($status === MarketplaceStatus::REMOTE_ONLY) {
            return [];
        }

        $canUpdate = $updateStatus === UpdateStatus::UPDATE_AVAILABLE && ! $incompatible;

        $actions = match ($status) {
            MarketplaceStatus::AVAILABLE, MarketplaceStatus::MISSING_FILES => $incompatible ? [] : ['discover'],
            MarketplaceStatus::DISCOVERED => $incompatible ? [] : ['install'],
            MarketplaceStatus::INSTALLED, MarketplaceStatus::DISABLED => array_values(array_filter([
                $canUpdate ? 'update' : null,
                'enable',
                'uninstall',
            ])),
            MarketplaceStatus::ENABLED => array_values(array_filter([
                $canUpdate ? 'update' : null,
                'disable',
                'uninstall',
            ])),
            MarketplaceStatus::FAILED => ['install', 'uninstall'],
            MarketplaceStatus::REMOVED => ['discover'],
            default => [],
        };

        // Blocking safety: incompatible or unmet dependency constraints block enable.
        if (in_array('enable', $actions, true) && ($dependencyIssues !== [] || $incompatible)) {
            $actions = array_values(array_diff($actions, ['enable']));
        }

        return $actions;
    }

    private function compatibilityStatus(MarketplaceItem $item): string
    {
        $constraint = $item->getPlatformConstraint();

        if ($constraint === null || $constraint === '' || $constraint === '*') {
            return CompatibilityStatus::UNKNOWN;
        }

        return $this->versionComparator->satisfies($this->platformVersion->version(), $constraint)
            ? CompatibilityStatus::COMPATIBLE
            : CompatibilityStatus::INCOMPATIBLE;
    }

    private function updateStatus(string $status, ?SystemAddon $addon, MarketplaceItem $item): string
    {
        if (! in_array($status, [
            MarketplaceStatus::INSTALLED,
            MarketplaceStatus::ENABLED,
            MarketplaceStatus::DISABLED,
            MarketplaceStatus::FAILED,
        ], true)) {
            return UpdateStatus::NOT_INSTALLED;
        }

        return $this->versionComparator->compareInstalled((string) ($addon?->version ?? ''), (string) $item->version);
    }

    /**
     * @return array<int, string>
     */
    public function dependencyIssues(MarketplaceItem $item): array
    {
        $issues = [];

        foreach ($item->getDependencies() as $dependency) {
            $dependencyCode = $dependency['code'];
            $constraint = $dependency['constraint'];
            $dependencyAddon = $this->registry->find($dependencyCode);

            if (! $dependencyAddon || ! $dependencyAddon->is_installed) {
                $issues[] = "Залежність [{$dependencyCode}] не встановлено.";

                continue;
            }

            if (! $dependencyAddon->is_enabled) {
                $issues[] = "Залежність [{$dependencyCode}] вимкнено.";
            }

            if ($constraint !== null && $constraint !== '' && $constraint !== '*'
                && ! $this->versionComparator->satisfies((string) $dependencyAddon->version, $constraint)) {
                $issues[] = "Версія залежності [{$dependencyCode}] ({$dependencyAddon->version}) не відповідає обмеженню [{$constraint}].";
            }
        }

        return $issues;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDependencyReport(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return [];
        }

        $compatibilityStatus = $this->compatibilityStatus($item);

        return $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );
    }

    public function canInstallDependencies(string $code): bool
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return false;
        }

        $report = $this->getDependencyReport($code);

        if ($report === []) {
            return false;
        }

        $graph = $this->resolver->buildGraph($this->catalog->load()['items']);
        $cycles = $this->resolver->detectCycles($graph);

        if ($cycles !== []) {
            return false;
        }

        foreach ($report as $dependencyReport) {
            $hasBlockingIssue = false;
            $hasOnlyNotInstalledIssue = false;

            foreach ($dependencyReport['issues'] as $issue) {
                if (str_contains($issue, 'не встановлено і локальні файли відсутні')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'не встановлено')) {
                    $hasOnlyNotInstalledIssue = true;
                } elseif (str_contains($issue, 'несумісна')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'некоректна')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'відсутній маніфест')) {
                    $hasBlockingIssue = true;
                } else {
                    $hasBlockingIssue = true;
                }
            }

            if ($hasBlockingIssue) {
                return false;
            }

            if (! $hasOnlyNotInstalledIssue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getBlockedReasons(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return ['Каталог не містить item ['.$code.'].'];
        }

        $addon = $this->registry->find($code);
        $compatibilityStatus = $this->compatibilityStatus($item);
        $dependencyIssues = $this->dependencyIssues($item);
        $blocked = [];

        if ($compatibilityStatus === CompatibilityStatus::INCOMPATIBLE) {
            $blocked[] = 'Несумісність з платформою.';
        }

        foreach ($dependencyIssues as $issue) {
            $blocked[] = $issue;
        }

        return $blocked;
    }

    public function canEnable(string $code): bool
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return false;
        }

        $addon = $this->registry->find($code);

        if ($addon === null || ! $addon->is_installed) {
            return false;
        }

        $compatibilityStatus = $this->compatibilityStatus($item);

        if ($compatibilityStatus === CompatibilityStatus::INCOMPATIBLE) {
            return false;
        }

        $dependencyIssues = $this->dependencyIssues($item);

        return $dependencyIssues === [];
    }

    public function installDependencies(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            throw new RuntimeException("Каталог не містить item [{$code}].");
        }

        $report = $this->getDependencyReport($code);
        $installed = [];
        $graph = $this->resolver->buildGraph($this->catalog->load()['items']);
        $cycles = $this->resolver->detectCycles($graph);

        if ($cycles !== []) {
            throw new RuntimeException('Неможливо встановити залежності: виявлено циклічні залежності — '.implode(', ', $cycles));
        }

        foreach ($report as $dependencyCode => $dependencyReport) {
            $hasBlockingIssue = false;

            foreach ($dependencyReport['issues'] as $issue) {
                if (str_contains($issue, 'не встановлено')) {
                    continue;
                }

                $hasBlockingIssue = true;
            }

            if ($hasBlockingIssue) {
                throw new RuntimeException('Неможливо встановити залежність ['.$dependencyCode.']: '.implode(' ', $dependencyReport['issues']));
            }

            $dependencyAddon = $this->registry->find($dependencyCode);

            if ($dependencyAddon === null || ! $dependencyAddon->is_installed) {
                $installedAddon = $this->manager->install($dependencyCode);
                $installed[] = $installedAddon->code;
                $this->events->info($dependencyCode, 'marketplace_dependency_installed', 'Dependency installed via parent addon.', [
                    'parent' => $code,
                ]);
            }
        }

        if ($installed !== []) {
            $parentAddon = $this->registry->find($code);

            if ($parentAddon !== null) {
                $this->events->info($code, 'marketplace_dependencies_installed', 'Dependencies installed.', [
                    'dependencies' => $installed,
                ]);
            }
        }

        return $installed;
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
     * Local update: records the available (catalog) version as the new installed
     * version without downloading any files. Keeps the current enabled/disabled
     * status untouched. Blocked when incompatible or when no update is available.
     */
    public function update(string $code): SystemAddon
    {
        $addon = $this->registry->find($code);

        if ($addon === null) {
            throw new RuntimeException("Addon [{$code}] не знайдено. Спочатку виконайте discover.");
        }

        $item = $this->findItem($code);

        if ($item === null) {
            throw new RuntimeException("Каталог не містить item [{$code}].");
        }

        $row = $this->resolveItem($item);

        if ($row['compatibility_status'] === CompatibilityStatus::INCOMPATIBLE) {
            throw new RuntimeException("Неможливо оновити [{$code}]: несумісно з поточною версією платформи.");
        }

        if ($row['update_status'] !== UpdateStatus::UPDATE_AVAILABLE) {
            throw new RuntimeException("Оновлення для [{$code}] недоступне (поточна версія вже актуальна або невідома).");
        }

        $dependencyIssues = $this->dependencyIssues($item);

        if ($dependencyIssues !== []) {
            throw new RuntimeException('Неможливо оновити ['.$code.']: невиконані залежності — '.implode(' ', $dependencyIssues));
        }

        $previousVersion = $addon->version;

        $addon->forceFill([
            'version' => $item->version,
            'is_installed' => true,
        ])->save();

        $this->events->info($code, 'marketplace_updated', "Addon updated to {$item->version}.", [
            'from' => $previousVersion,
            'to' => $item->version,
        ]);

        return $addon->refresh();
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

        $remoteCatalog = $this->loadRemoteCatalog();

        foreach ($remoteCatalog['items'] ?? [] as $item) {
            if ($item->code === $code) {
                return MarketplaceItem::fromArray($item->raw);
            }
        }

        return null;
    }
}
