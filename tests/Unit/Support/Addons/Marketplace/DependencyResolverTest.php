<?php

namespace Tests\Unit\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\Marketplace\DependencyResolver;
use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Version\VersionComparator;
use PHPUnit\Framework\TestCase;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    private VersionComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DependencyResolver;
        $this->comparator = new VersionComparator;
    }

    public function test_build_graph_returns_nodes_for_dependencies(): void
    {
        $items = [
            MarketplaceItem::fromArray([
                'code' => 'core.promotions',
                'type' => 'module',
                'name' => 'Promotions',
                'version' => '1.0.0',
                'vendor' => 'Core',
                'dependencies' => ['core.products'],
            ]),
        ];

        $graph = $this->resolver->buildGraph($items);

        $this->assertArrayHasKey('core.promotions', $graph);
        $this->assertCount(1, $graph['core.promotions']);
        $this->assertSame('core.products', $graph['core.promotions'][0]['code']);
    }

    public function test_detect_cycles_returns_empty_for_acyclic_graph(): void
    {
        $graph = [
            'core.promotions' => [
                ['code' => 'core.products', 'constraint' => null, 'depth' => 1],
            ],
            'core.products' => [],
        ];

        $this->assertSame([], $this->resolver->detectCycles($graph));
    }

    public function test_detect_cycles_finds_simple_cycle(): void
    {
        $graph = [
            'core.a' => [
                ['code' => 'core.b', 'constraint' => null, 'depth' => 1],
            ],
            'core.b' => [
                ['code' => 'core.a', 'constraint' => null, 'depth' => 1],
            ],
        ];

        $cycles = $this->resolver->detectCycles($graph);

        $this->assertNotEmpty($cycles);
        $this->assertStringContainsString('core.a', $cycles[0]);
        $this->assertStringContainsString('core.b', $cycles[0]);
    }

    public function test_resolve_item_dependencies_returns_missing_issue_when_dependency_not_installed(): void
    {
        $item = MarketplaceItem::fromArray([
            'code' => 'core.promotions',
            'type' => 'module',
            'name' => 'Promotions',
            'version' => '1.0.0',
            'vendor' => 'Core',
            'dependencies' => ['core.products'],
        ]);

        $registry = new class extends AddonRegistry
        {
            public function find(string $code): ?SystemAddon
            {
                return null;
            }
        };

        $report = $this->resolver->resolveItemDependencies($item, $registry, $this->comparator, [$item]);

        $this->assertArrayHasKey('core.products', $report);
        $this->assertNotEmpty($report['core.products']['issues']);
    }
}
