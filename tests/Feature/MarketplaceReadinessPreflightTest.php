<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\AddonRecoveryHealthService;
use App\Support\Addons\Registry\MarketplaceReadinessService;
use App\Support\Addons\Registry\RecoveryDataCleanupService;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarketplaceReadinessPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('addons');
        Cache::flush();
        foreach (['addons/quarantine', 'addons/staging', 'addons/backups'] as $path) {
            Storage::disk('addons')->makeDirectory($path);
        }
        $modules = Storage::disk('addons')->path('live/modules');
        $extensions = Storage::disk('addons')->path('live/extensions');
        mkdir($modules, 0755, true);
        mkdir($extensions, 0755, true);
        config([
            'addons-registry.enabled' => false,
            'addons-registry.downloads.disk' => 'addons',
            'addons-registry.downloads.quarantine_path' => 'addons/quarantine',
            'addons-registry.staging.path' => 'addons/staging',
            'addons-registry.promotion.backup_path' => 'addons/backups',
            'addons-registry.live_roots.modules_path' => $modules,
            'addons-registry.live_roots.extensions_path' => $extensions,
            'addons-registry.trust.keys' => [],
            'addons-registry.trust.trusted_keys' => [],
            'addons-registry.trust.legacy_publishers' => [],
        ]);
    }

    public function test_local_readiness_is_sanitized_read_only_and_ready_with_expected_warnings(): void
    {
        $before = Storage::disk('addons')->allFiles();
        $result = app(MarketplaceReadinessService::class)->inspect();

        $this->assertSame('ready_with_warnings', $result['status']);
        $this->assertSame(0, $result['blocker_count']);
        $this->assertContains('trust_store_empty', array_column($result['items'], 'code'));
        $this->assertSame($before, Storage::disk('addons')->allFiles());
        $this->assertStringNotContainsString(Storage::disk('addons')->path(''), json_encode($result));
        $this->artisan('addons:marketplace:preflight --json')->assertSuccessful()->expectsOutputToContain('ready_with_warnings');
    }

    public function test_missing_sodium_and_invalid_production_policy_block(): void
    {
        config([
            'addons-registry.enabled' => true,
            'addons-registry.url' => 'http://registry.example.test/api/v1/registry',
            'addons-registry.allowed_hosts' => ['wrong.example.test'],
            'addons-registry.verify_ssl' => false,
        ]);
        $service = new MarketplaceReadinessService(
            app(AddonRecoveryHealthService::class), app(RecoveryDataCleanupService::class), app(RegistryCatalog::class),
            ['extensions' => ['sodium' => false, 'zip' => true, 'json' => true, 'hash' => true], 'php_version' => PHP_VERSION],
        );
        $result = $service->inspect();
        $codes = array_column(array_filter($result['items'], fn (array $item): bool => $item['severity'] === 'blocker'), 'code');

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('runtime_sodium', $codes);
        $this->assertContains('registry_https', $codes);
        $this->assertContains('registry_host_allowed', $codes);
        $this->assertContains('registry_tls_verification', $codes);
    }

    public function test_malformed_duplicate_and_test_trust_entries_fail_closed(): void
    {
        $entry = ['publisher_id' => '11111111-1111-4111-8111-111111111111', 'key_id' => 'test-key', 'algorithm' => 'ed25519', 'public_key' => base64_encode(random_bytes(31)), 'status' => 'active'];
        config(['addons-registry.trust.keys' => [$entry, $entry]]);

        $result = app(MarketplaceReadinessService::class)->inspect(true);
        $trust = array_values(array_filter($result['items'], fn (array $item): bool => str_starts_with($item['code'], 'trust_entry_')));
        $this->assertCount(2, $trust);
        $this->assertSame(['blocker', 'blocker'], array_column($trust, 'severity'));
    }

    public function test_explicit_production_smoke_accepts_empty_registry_and_uses_conditional_request(): void
    {
        config([
            'addons-registry.enabled' => true,
            'addons-registry.url' => 'https://registry.example.test/api/v1/registry',
            'addons-registry.allowed_hosts' => ['registry.example.test'],
            'addons-registry.verify_ssl' => true,
            'addons-registry.allow_redirects' => false,
        ]);
        $document = ['registry' => ['name' => 'ALTA', 'version' => 'build-1', 'application_version' => '1.0.0', 'build_version' => 'build-1', 'schema_version' => '1', 'generated_at' => '2026-07-15T00:00:00+00:00'], 'items' => []];
        Http::fake(fn ($request) => $request->hasHeader('If-None-Match')
            ? Http::response('', 304, ['ETag' => '"one"'])
            : Http::response($document, 200, ['Content-Type' => 'application/json', 'ETag' => '"one"']));
        $catalog = new RegistryCatalog(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $service = new MarketplaceReadinessService(app(AddonRecoveryHealthService::class), app(RecoveryDataCleanupService::class), $catalog);

        $result = $service->inspect(true);
        $codes = array_column($result['items'], 'code');
        $this->assertContains('registry_remote_200', $codes);
        $this->assertContains('registry_remote_304', $codes);
        $this->assertContains('registry_empty', $codes);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/artifacts/'));
    }
}
