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
        Cache::flush();
    }

    private function config(): array
    {
        return ['enabled' => true, 'url' => 'https://registry.example.test/api/v1/registry', 'allowed_hosts' => ['registry.example.test'], 'verify_ssl' => true, 'cache_ttl' => 0, 'downloads' => ['max_size' => 10000]];
    }

    private function document(array $items = []): array
    {
        return ['registry' => ['name' => 'Test', 'version' => 'build-1', 'application_version' => '1.0.0', 'build_version' => 'build-1', 'schema_version' => '1', 'generated_at' => '1970-01-01T00:00:00+00:00'], 'items' => $items];
    }

    public function test_valid_empty_200_creates_fresh_snapshot_then_304_preserves_validation(): void
    {
        Http::fakeSequence()->push($this->document(), 200, ['Content-Type' => 'application/json', 'ETag' => '"one"', 'Last-Modified' => 'Tue, 14 Jul 2026 00:00:00 GMT'])->push('', 304, ['ETag' => '"one"']);
        $catalog = new RegistryCatalog(new RegistryClient($this->config()), $this->config());
        $first = $catalog->refresh();
        $validatedAt = $first['meta']['validated_at'];
        $second = $catalog->refresh();
        $this->assertSame('fresh', $second['state']);
        $this->assertSame([], $second['items']);
        $this->assertSame(304, $second['meta']['last_http_status']);
        $this->assertSame($validatedAt, $second['meta']['validated_at']);
    }

    public function test_network_429_and_invalid_schema_preserve_last_valid_snapshot(): void
    {
        Http::fakeSequence()->push($this->document(), 200, ['Content-Type' => 'application/json'])->pushStatus(500)->push('', 429, ['Retry-After' => '60'])->push(['registry' => ['schema_version' => '2'], 'items' => []], 200, ['Content-Type' => 'application/json']);
        $catalog = new RegistryCatalog(new RegistryClient($this->config()), $this->config());
        $catalog->refresh();
        $this->assertSame('offline', $catalog->refresh()['state']);
        $this->assertSame('rate_limited', $catalog->refresh()['state']);
        $invalid = $catalog->refresh();
        $this->assertSame('stale', $invalid['state']);
        $this->assertSame('1', $invalid['registry']['schema_version']);
    }

    public function test_failure_without_snapshot_is_unavailable_and_does_not_create_trusted_empty_catalog(): void
    {
        Http::fake(['*' => Http::response('bad', 200, ['Content-Type' => 'application/json'])]);
        $result = (new RegistryCatalog(new RegistryClient($this->config()), $this->config()))->refresh();
        $this->assertSame('unavailable', $result['state']);
        $this->assertNull(Cache::get('addons.registry.catalog.snapshot.v1'));
    }

    public function test_successful_200_atomically_replaces_prior_snapshot(): void
    {
        Http::fakeSequence()->push($this->document(), 200, ['Content-Type' => 'application/json'])->push($this->document(), 200, ['Content-Type' => 'application/json', 'ETag' => '"new"']);
        $catalog = new RegistryCatalog(new RegistryClient($this->config()), $this->config());
        $catalog->refresh();
        $result = $catalog->refresh();
        $this->assertSame('"new"', $result['meta']['etag']);
        $this->assertSame('fresh', $result['state']);
    }
}
