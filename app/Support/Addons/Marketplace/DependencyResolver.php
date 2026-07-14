<?php

namespace App\Support\Addons\Marketplace;

use App\Support\Addons\AddonRegistry;
use App\Support\Addons\PlatformVersion;
use App\Support\Addons\Registry\RegistryItem;
use App\Support\Addons\Version\VersionComparator;

/**
 * Resolves local addon dependency graphs for the marketplace.
 *
 * It does NOT install anything by itself. It only inspects the local catalog
 * and system_addons registry, then returns readable diagnostics and installability
 * hints for the UI / service layer.
 */
final class DependencyResolver
{
    public function __construct(
        private readonly PlatformVersion $platformVersion = new PlatformVersion,
    ) {}

    /**
     * @param  array<int, MarketplaceItem>  $items
     * @return array<string, array<int, array{code: string, constraint: string|null, depth: int}>>
     */
    public function buildGraph(array $items): array
    {
        $graph = [];

        foreach ($items as $item) {
            $graph[$item->code] = array_map(
                static fn (array $dependency): array => [
                    'code' => $dependency['code'],
                    'constraint' => $dependency['constraint'],
                    'depth' => 1,
                ],
                $item->getDependencies(),
            );
        }

        return $graph;
    }

    /**
     * @param  array<string, array<int, array{code: string, constraint: string|null, depth: int}>>  $graph
     * @return array<int, string>
     */
    public function detectCycles(array $graph): array
    {
        $cycles = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($graph) as $node) {
            $this->detectCycleFromNode($node, $graph, $visited, $recursionStack, $cycles);
        }

        return array_values(array_unique($cycles));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resolveItemDependencies(
        MarketplaceItem $item,
        AddonRegistry $registry,
        VersionComparator $comparator,
        array $allItems,
        string $itemCompatibilityStatus = 'unknown',
    ): array {
        $resolved = [];
        $direct = $item->getDependencies();

        foreach ($direct as $dependency) {
            $code = $dependency['code'];
            $constraint = $dependency['constraint'];

            $resolved[$code] = [
                'code' => $code,
                'constraint' => $constraint,
                'direct' => true,
                'issues' => $this->resolveDependencyIssues($code, $constraint, $registry, $comparator, $allItems),
            ];
        }

        return $resolved;
    }

    /**
     * Read-only install/update preflight. It never mutates local state.
     *
     * @param  array<string, MarketplaceItem>  $localItems
     * @param  array<string, RegistryItem>  $remoteItems
     * @param  array<string, list<string>>  $identityConflicts
     */
    public function preflight(
        MarketplaceItem|RegistryItem $root,
        AddonRegistry $registry,
        VersionComparator $comparator,
        array $localItems,
        array $remoteItems,
        string $registryState,
        array $identityConflicts = [],
    ): array {
        $nodes = [];
        $plan = [];
        $cycles = [];
        $visiting = [];
        $visited = [];

        $visit = function (MarketplaceItem|RegistryItem $candidate, array $path = []) use (&$visit, &$nodes, &$plan, &$cycles, &$visiting, &$visited, $root, $registry, $comparator, $localItems, $remoteItems, $registryState, $identityConflicts): void {
            $code = $candidate->code;
            if (isset($visiting[$code])) {
                $start = array_search($code, $path, true);
                $cycle = [...array_slice($path, $start === false ? 0 : $start), $code];
                $cycles[] = implode(' -> ', $cycle);

                return;
            }
            if (isset($visited[$code])) {
                return;
            }
            $visiting[$code] = true;
            $path[] = $code;
            $dependencies = $candidate instanceof RegistryItem ? $candidate->dependencies : $candidate->getDependencies();
            usort($dependencies, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

            foreach ($dependencies as $dependency) {
                $dependencyCode = $dependency['code'];
                $constraint = $dependency['constraint'] ?? null;
                $required = (bool) ($dependency['required'] ?? true);
                $installed = $registry->find($dependencyCode);
                $local = $localItems[$dependencyCode] ?? null;
                $remote = $remoteItems[$dependencyCode] ?? null;
                $installedSatisfied = $installed?->is_installed && ($constraint === null || $constraint === '' || $constraint === '*' || $comparator->satisfies((string) $installed->version, $constraint));
                $localAvailable = $local?->isValid() && $local->path !== null && is_file(base_path($local->path));
                $remoteAvailable = $remote !== null && $registryState === 'fresh';

                $state = match (true) {
                    isset($identityConflicts[$dependencyCode]) => 'identity_conflict',
                    $installed?->is_installed && ! $installedSatisfied => 'installed_version_mismatch',
                    $installedSatisfied => 'satisfied_installed',
                    $localAvailable && $remoteAvailable => 'available_local_and_remote',
                    $localAvailable => 'available_local',
                    $remoteAvailable => 'available_remote',
                    $remote !== null => 'remote_stale_or_unavailable',
                    default => 'missing',
                };
                $blocking = $required && in_array($state, ['installed_version_mismatch', 'identity_conflict', 'remote_stale_or_unavailable', 'missing'], true);
                $nodes[$dependencyCode] = [
                    'code' => $dependencyCode, 'constraint' => $constraint, 'required' => $required, 'state' => $state,
                    'installed' => (bool) $installed?->is_installed, 'enabled' => (bool) $installed?->is_enabled,
                    'installed_version' => $installed?->version, 'local_version' => $local?->version, 'remote_version' => $remote?->version,
                    'blocking' => $blocking, 'reason' => $this->nodeReason($state, $constraint),
                ];

                $next = $remoteAvailable ? $remote : ($localAvailable ? $local : null);
                if ($required && $next !== null && ! $installedSatisfied) {
                    $visit($next, $path);
                }
            }
            unset($visiting[$code]);
            $visited[$code] = true;
            if ($code !== $root->code && isset($nodes[$code]) && ! $nodes[$code]['installed']) {
                $plan[] = $code;
            }
        };
        $visit($root);
        $plan = array_values(array_unique($plan));
        foreach ($cycles as $cycle) {
            foreach (explode(' -> ', $cycle) as $code) {
                if (isset($nodes[$code])) {
                    $nodes[$code]['state'] = 'cycle';
                    $nodes[$code]['blocking'] = true;
                    $nodes[$code]['reason'] = 'Dependency cycle: '.$cycle;
                }
            }
        }
        $blocking = array_values(array_filter($nodes, fn (array $node): bool => $node['blocking']));
        $state = $cycles !== [] ? 'blocked_cycle' : ($blocking !== [] ? $this->blockedState($blocking) : ($plan !== [] ? 'installable' : 'satisfied'));

        return ['state' => $state, 'nodes' => $nodes, 'plan' => $plan, 'cycles' => array_values(array_unique($cycles)), 'blocking' => $blocking];
    }

    private function nodeReason(string $state, ?string $constraint): string
    {
        return match ($state) {
            'satisfied_installed' => 'Installed dependency satisfies the constraint.',
            'installed_version_mismatch' => 'Installed dependency requires update for constraint ['.($constraint ?? '*').'].',
            'available_local' => 'Dependency is available from the local catalog.',
            'available_remote' => 'Dependency is available from the fresh Registry.',
            'available_local_and_remote' => 'Dependency is available from local and remote catalogs.',
            'remote_stale_or_unavailable' => 'Remote dependency exists but Registry is not fresh.',
            'identity_conflict' => 'Dependency identity conflicts between local and remote sources.',
            default => 'Dependency is missing.',
        };
    }

    private function blockedState(array $blocking): string
    {
        $states = array_column($blocking, 'state');
        if (in_array('identity_conflict', $states, true)) {
            return 'blocked_identity_conflict';
        }
        if (in_array('installed_version_mismatch', $states, true)) {
            return 'blocked_version';
        }
        if (in_array('remote_stale_or_unavailable', $states, true)) {
            return 'unknown_remote_state';
        }

        return 'blocked_missing';
    }

    /**
     * @return array<int, string>
     */
    private function resolveDependencyIssues(
        string $code,
        ?string $constraint,
        AddonRegistry $registry,
        VersionComparator $comparator,
        array $allItems,
    ): array {
        $issues = [];
        $addon = $registry->find($code);

        if (! $addon || ! $addon->is_installed) {
            $catalogItem = null;

            foreach ($allItems as $candidate) {
                if ($candidate->code === $code) {
                    $catalogItem = $candidate;

                    break;
                }
            }

            if ($catalogItem === null || $catalogItem->path === null || ! is_file(base_path($catalogItem->path))) {
                $issues[] = 'Залежність ['.$code.'] не встановлено і локальні файли відсутні.';
            } elseif ($catalogItem->getPlatformConstraint() !== null && $catalogItem->getPlatformConstraint() !== '' && $catalogItem->getPlatformConstraint() !== '*'
                && ! $comparator->satisfies($this->platformVersion->version(), $catalogItem->getPlatformConstraint())) {
                $issues[] = 'Залежність ['.$code.'] несумісна з платформою.';
            } elseif (! $catalogItem->isValid()) {
                $issues[] = 'Залежність ['.$code.'] некоректна в каталозі.';
            } else {
                $issues[] = 'Залежність ['.$code.'] не встановлено.';
            }

            return $issues;
        }

        if (! $addon->is_enabled) {
            $issues[] = 'Залежність ['.$code.'] вимкнено.';
        }

        if ($constraint !== null && $constraint !== '' && $constraint !== '*'
            && ! $comparator->satisfies((string) $addon->version, $constraint)) {
            $issues[] = 'Версія залежності ['.$code.'] ('.$addon->version.') не відповідає обмеженню ['.$constraint.'].';
        }

        if ($addon->manifest_path && ! is_file(base_path($addon->manifest_path))) {
            $issues[] = 'Залежність ['.$code.'] має відсутній маніфест.';
        }

        return $issues;
    }

    /**
     * @param  array<string, array<int, array{code: string, constraint: string|null, depth: int}>>  $graph
     * @return array<int, string>
     */
    private function detectCycleFromNode(
        string $node,
        array $graph,
        array &$visited,
        array &$recursionStack,
        array &$cycles,
    ): void {
        $visited[$node] = true;
        $recursionStack[$node] = true;

        foreach ($graph[$node] ?? [] as $edge) {
            $neighbor = $edge['code'];

            if (! isset($graph[$neighbor])) {
                continue;
            }

            if (isset($recursionStack[$neighbor]) && $recursionStack[$neighbor]) {
                $cycles[] = $node.' -> '.$neighbor;
            } elseif (! isset($visited[$neighbor])) {
                $this->detectCycleFromNode($neighbor, $graph, $visited, $recursionStack, $cycles);
            }
        }

        $recursionStack[$node] = false;
    }
}
