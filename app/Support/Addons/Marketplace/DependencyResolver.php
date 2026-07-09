<?php

namespace App\Support\Addons\Marketplace;

use App\Support\Addons\AddonRegistry;
use App\Support\Addons\PlatformVersion;
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
