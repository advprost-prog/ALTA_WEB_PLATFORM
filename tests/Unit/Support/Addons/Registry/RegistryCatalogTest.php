<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistryCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('addons.registry.catalog');
    }

    public function test_disabled_registry_returns_empty_catalog(): void
    {
        $client = new RegistryClient(['enabled' => false, 'url' => 'http://example.test']);
        $catalog = new RegistryCatalog($client, ['enabled' => false, 'cache_ttl' => 3600]);

        $result = $catalog->load();

        $this->assertEmpty($result['items']);
        $this->assertContains('Registry is disabled.', $result['diagnostics']);
    }

    public function test_valid_registry_payload_normalizes_items(): void
    {
        Http::fake([
            'http://example.test' => Http::response([
                'registry' => ['name' => 'Test', 'version' => '1.0.0'],
                'items' => [
                    [
                        'code' => 'core.theme-maker',
                        'type' => 'extension',
                        'vendor' => 'Core',
                        'name' => 'Theme Maker',
                        'description' => 'Demo',
                        'version' => '0.3.0',
                        'requires_platform' => '>=1.0.0',
                        'dependencies' => [],
                        'is_featured' => true,
                    ],
                ],
            ], 200),
        ]);

        $client = new RegistryClient([
            'enabled' => true,
            'url' => 'http://example.test',
            'cache_ttl' => 3600,
        ]);
        $catalog = new RegistryCatalog($client, ['enabled' => true, 'cache_ttl' => 3600]);

        $result = $catalog->load();

        $this->assertCount(1, $result['items']);
        $this->assertSame('core.theme-maker', $result['items'][0]->code);
        $this->assertSame('0.3.0', $result['items'][0]->version);
    }
}
