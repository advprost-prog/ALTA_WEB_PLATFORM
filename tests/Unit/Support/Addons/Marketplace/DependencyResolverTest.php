<?php

namespace Tests\Unit\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\Marketplace\DependencyResolver;
use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Registry\RegistryItem;
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

    public function test_remote_preflight_builds_stable_dependency_first_transitive_plan(): void
    {
        $root = $this->remote('core.a', [['code' => 'core.c', 'constraint' => null, 'required' => true], ['code' => 'core.b', 'constraint' => null, 'required' => true]]);
        $remote = [
            'core.b' => $this->remote('core.b', [['code' => 'core.d', 'constraint' => null, 'required' => true]]),
            'core.c' => $this->remote('core.c'), 'core.d' => $this->remote('core.d'),
        ];

        $result = $this->resolver->preflight($root, $this->registry(), $this->comparator, [], $remote, 'fresh');

        $this->assertSame('installable', $result['state']);
        $this->assertSame(['core.d', 'core.b', 'core.c'], $result['plan']);
        $this->assertSame('available_remote', $result['nodes']['core.b']['state']);
    }

    public function test_remote_preflight_reports_installed_mismatch_optional_missing_and_satisfied_enable_state(): void
    {
        $installed = new SystemAddon(['code' => 'core.dep', 'version' => '1.0.0', 'is_installed' => true, 'is_enabled' => false]);
        $root = $this->remote('core.a', [
            ['code' => 'core.dep', 'constraint' => '^2.0', 'required' => true],
            ['code' => 'core.optional', 'constraint' => null, 'required' => false],
        ]);
        $result = $this->resolver->preflight($root, $this->registry(['core.dep' => $installed]), $this->comparator, [], [], 'fresh');

        $this->assertSame('blocked_version', $result['state']);
        $this->assertSame('installed_version_mismatch', $result['nodes']['core.dep']['state']);
        $this->assertFalse($result['nodes']['core.optional']['blocking']);
        $this->assertFalse($result['nodes']['core.dep']['enabled']);
    }

    public function test_install_preflight_accepts_satisfying_installed_dependency_without_requiring_enabled(): void
    {
        $installed = new SystemAddon(['code' => 'core.dep', 'version' => '2.1.0', 'is_installed' => true, 'is_enabled' => false]);
        $root = $this->remote('core.a', [['code' => 'core.dep', 'constraint' => '^2.0', 'required' => true]]);
        $result = $this->resolver->preflight($root, $this->registry(['core.dep' => $installed]), $this->comparator, [], [], 'fresh');

        $this->assertSame('satisfied', $result['state']);
        $this->assertSame('satisfied_installed', $result['nodes']['core.dep']['state']);
        $this->assertFalse($result['nodes']['core.dep']['enabled']);
    }

    public function test_remote_preflight_detects_self_and_multi_node_cycles_with_paths(): void
    {
        $self = $this->remote('core.a', [['code' => 'core.a', 'constraint' => null, 'required' => true]]);
        $selfResult = $this->resolver->preflight($self, $this->registry(), $this->comparator, [], ['core.a' => $self], 'fresh');
        $this->assertSame('blocked_cycle', $selfResult['state']);
        $this->assertSame('core.a -> core.a', $selfResult['cycles'][0]);

        $a = $this->remote('core.a', [['code' => 'core.b', 'constraint' => null, 'required' => true]]);
        $b = $this->remote('core.b', [['code' => 'core.c', 'constraint' => null, 'required' => true]]);
        $c = $this->remote('core.c', [['code' => 'core.a', 'constraint' => null, 'required' => true]]);
        $result = $this->resolver->preflight($a, $this->registry(), $this->comparator, [], ['core.a' => $a, 'core.b' => $b, 'core.c' => $c], 'fresh');
        $this->assertSame('blocked_cycle', $result['state']);
        $this->assertStringContainsString('core.a -> core.b -> core.c -> core.a', $result['cycles'][0]);
    }

    public function test_remote_preflight_blocks_stale_remote_and_identity_conflict(): void
    {
        $root = $this->remote('core.a', [['code' => 'core.dep', 'constraint' => null, 'required' => true]]);
        $dep = $this->remote('core.dep');
        $stale = $this->resolver->preflight($root, $this->registry(), $this->comparator, [], ['core.dep' => $dep], 'stale');
        $conflict = $this->resolver->preflight($root, $this->registry(), $this->comparator, [], ['core.dep' => $dep], 'fresh', ['core.dep' => ['vendor']]);
        $this->assertSame('unknown_remote_state', $stale['state']);
        $this->assertSame('blocked_identity_conflict', $conflict['state']);
    }

    public function test_required_missing_dependency_blocks_plan(): void
    {
        $root = $this->remote('core.a', [['code' => 'core.missing', 'constraint' => null, 'required' => true]]);
        $result = $this->resolver->preflight($root, $this->registry(), $this->comparator, [], [], 'fresh');

        $this->assertSame('blocked_missing', $result['state']);
        $this->assertSame([], $result['plan']);
    }

    private function remote(string $code, array $dependencies = []): RegistryItem
    {
        return RegistryItem::fromArray(['code' => $code, 'type' => 'module', 'vendor' => 'Core', 'name' => $code, 'description' => '', 'version' => '1.0.0', 'dependencies' => $dependencies, 'artifact' => ['signature' => ['key_id' => 'key']]]);
    }

    private function registry(array $addons = []): AddonRegistry
    {
        return new class($addons) extends AddonRegistry
        {
            public function __construct(private array $addons) {}

            public function find(string $code): ?SystemAddon
            {
                return $this->addons[$code] ?? null;
            }
        };
    }
}
