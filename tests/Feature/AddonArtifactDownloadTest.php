<?php

namespace Tests\Feature;

use App\Console\Commands\Addons\DownloadAddon;
use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactDownloader;
use App\Support\Addons\Registry\ArtifactValidator;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use App\Support\Addons\Registry\RegistryItem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonArtifactDownloadTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private string $registryUrl = 'http://127.0.0.1:9001/registry.example.json';

    private string $artifactUrl = 'http://127.0.0.1:9001/artifacts/core.analytics-1.0.0.zip';

    private string $quarantineDir = 'addons/quarantine/core.analytics/1.0.0';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('addons')->deleteDirectory('addons/quarantine');
    }

    protected function tearDown(): void
    {
        Storage::disk('addons')->deleteDirectory('addons/quarantine');

        parent::tearDown();
    }

    /**
     * Enable the registry and (optionally) artifact downloads, rebinding the
     * singletons so the live MarketplaceManager picks up the new config.
     */
    private function configureRegistry(bool $downloadsEnabled, array $overrides = []): void
    {
        $realTrust = config('addons-registry.trust', []);

        $config = array_merge([
            'enabled' => true,
            'url' => $this->registryUrl,
            'timeout' => 5,
            'cache_ttl' => 60,
            'allowed_hosts' => [],
            'verify_ssl' => true,
            'allow_localhost' => true,
            'mode' => 'read_only',
            'trust' => $realTrust,
            'downloads' => [
                'enabled' => $downloadsEnabled,
                'disk' => 'addons',
                'quarantine_path' => 'addons/quarantine',
                'max_size' => 20 * 1024 * 1024,
                'allowed_types' => ['zip'],
                'allowed_extensions' => ['zip'],
            ],
        ], $overrides);

        config(['addons-registry' => $config]);

        app()->forgetInstance(RegistryClient::class);
        app()->forgetInstance(RegistryCatalog::class);
        app()->forgetInstance(MarketplaceManager::class);

        app()->singleton(RegistryClient::class, fn () => new RegistryClient(config('addons-registry')));
        app()->singleton(RegistryCatalog::class, fn ($app) => new RegistryCatalog(
            $app->make(RegistryClient::class),
            config('addons-registry'),
        ));

        app(RegistryCatalog::class)->flush();
    }

    private function fakeRegistryAndArtifact(string $artifactBody, ?string $registrySha256 = null): void
    {
        $registryPath = base_path('docs/examples/registry.example.json');
        $registry = json_decode((string) file_get_contents($registryPath), true);
        $registry['registry'] += [
            'application_version' => '1.0.0',
            'build_version' => 'test-build',
            'schema_version' => '1',
        ];
        $registry['registry']['version'] = 'test-build';
        $registry['items'] = array_values(array_filter($registry['items'], fn (array $item) => $item['code'] === 'core.analytics'));
        foreach ($registry['items'] as &$schemaItem) {
            $schemaItem['publisher'] = ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Test Publisher'];
            $schemaItem['published_at'] = '2026-07-14T00:00:00+00:00';
            $schemaItem['artifact']['signature']['payload_version'] = 'raw-zip-v1';
        }
        unset($schemaItem);

        if ($registrySha256 !== null) {
            foreach ($registry['items'] as &$item) {
                if (($item['code'] ?? '') === 'core.analytics') {
                    $item['artifact']['sha256'] = $registrySha256;
                }
            }
            unset($item);
        }

        Http::fake([
            $this->registryUrl => Http::response($registry, 200, ['Content-Type' => 'application/json']),
            $this->artifactUrl => Http::response($artifactBody, 200, ['Content-Type' => 'application/zip']),
        ]);
    }

    private function realArtifactBytes(): string
    {
        return (string) file_get_contents(base_path('docs/examples/artifacts/core.analytics-1.0.0.zip'));
    }

    private function realArtifactSha256(): string
    {
        return hash_file('sha256', base_path('docs/examples/artifacts/core.analytics-1.0.0.zip'));
    }

    /* -------------------------------------------------------------------------
     | ArtifactValidator
     | ---------------------------------------------------------------------- */

    public function test_validator_accepts_valid_zip_metadata(): void
    {
        $validator = new ArtifactValidator;
        $artifact = [
            'url' => $this->artifactUrl,
            'type' => 'zip',
            'sha256' => $this->realArtifactSha256(),
            'size' => 358,
            'signature' => null,
        ];

        $this->assertSame([], $validator->validateMetadata($artifact));
    }

    public function test_validator_rejects_missing_sha256(): void
    {
        $validator = new ArtifactValidator;
        $artifact = ['url' => $this->artifactUrl, 'type' => 'zip', 'sha256' => '', 'size' => 358];

        $issues = $validator->validateMetadata($artifact);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsStringIgnoringCase('sha256', $issues[0]);
    }

    public function test_validator_rejects_unsupported_type(): void
    {
        $validator = new ArtifactValidator;
        $artifact = ['url' => $this->artifactUrl, 'type' => 'tar', 'sha256' => $this->realArtifactSha256(), 'size' => 358];

        $issues = $validator->validateMetadata($artifact);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsStringIgnoringCase('Unsupported artifact type', implode(' ', $issues));
    }

    public function test_validator_normalized_artifact_supports_nullable_signature(): void
    {
        $validator = new ArtifactValidator;
        $normalized = $validator->normalizedArtifact(['url' => $this->artifactUrl, 'type' => 'zip', 'sha256' => 'x', 'size' => 1]);

        $this->assertNull($normalized['signature']);
        $this->assertSame('zip', $normalized['type']);
    }

    /* -------------------------------------------------------------------------
     | ArtifactDownloader
     | ---------------------------------------------------------------------- */

    public function test_downloader_stores_valid_artifact_in_quarantine(): void
    {
        $this->configureRegistry(true);
        $bytes = $this->realArtifactBytes();

        Http::fake([
            $this->artifactUrl => Http::response($bytes, 200, ['Content-Type' => 'application/zip']),
        ]);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => $this->artifactUrl,
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => strlen($bytes),
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertTrue($result->success);
        $this->assertSame('quarantined', $result->status);

        $disk = Storage::disk('addons');
        $this->assertTrue($disk->exists($this->quarantineDir.'/core.analytics-1.0.0.zip'));
        $this->assertTrue($disk->exists($this->quarantineDir.'/metadata.json'));

        $metadata = json_decode($disk->get($this->quarantineDir.'/metadata.json'), true);
        $this->assertSame('quarantined', $metadata['status']);
        $this->assertSame($this->realArtifactSha256(), $metadata['sha256']);
    }

    public function test_downloader_rejects_checksum_mismatch(): void
    {
        $this->configureRegistry(true);

        Http::fake([
            $this->artifactUrl => Http::response('totally different payload', 200),
        ]);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => $this->artifactUrl,
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => 358,
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertFalse($result->success);
        $this->assertSame('rejected', $result->status);
        $this->assertFalse(Storage::disk('addons')->exists($this->quarantineDir.'/core.analytics-1.0.0.zip'));
    }

    public function test_downloader_accepts_production_download_url_and_uses_trusted_zip_filename(): void
    {
        $this->configureRegistry(true);

        Http::fake([
            'http://127.0.0.1:9001/api/v1/artifacts/11111111-1111-4111-8111-111111111111/download' => Http::response($this->realArtifactBytes(), 200),
        ]);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => 'http://127.0.0.1:9001/api/v1/artifacts/11111111-1111-4111-8111-111111111111/download',
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => 358,
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertTrue($result->success);
        $this->assertSame($this->quarantineDir.'/core.analytics-1.0.0.zip', $result->path);
    }

    public function test_trusted_filename_sanitizes_untrusted_path_characters(): void
    {
        $filename = ArtifactDownloader::safeFilename('../core/analytics', "1.0.0\0/../../escape");

        $this->assertSame('core-analytics-1.0.0-..-..-escape.zip', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString("\0", $filename);
    }

    public function test_downloader_blocks_when_disabled(): void
    {
        $this->configureRegistry(false);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => $this->artifactUrl,
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => 358,
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertFalse($result->success);
        $this->assertSame('downloads_disabled', $result->status);
    }

    public function test_downloader_blocks_not_allowed_host(): void
    {
        $this->configureRegistry(true, [
            'allow_localhost' => false,
            'allowed_hosts' => ['registry.example.com'],
        ]);

        Http::fake([
            $this->artifactUrl => Http::response($this->realArtifactBytes(), 200),
        ]);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => $this->artifactUrl,
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => 358,
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertFalse($result->success);
        $this->assertSame('host_not_allowed', $result->status);
    }

    public function test_downloader_rejects_size_exceeding_max(): void
    {
        $this->configureRegistry(true, [
            'downloads' => [
                'enabled' => true,
                'disk' => 'addons',
                'quarantine_path' => 'addons/quarantine',
                'max_size' => 10,
                'allowed_types' => ['zip'],
                'allowed_extensions' => ['zip'],
            ],
        ]);

        Http::fake([
            $this->artifactUrl => Http::response($this->realArtifactBytes(), 200),
        ]);

        $item = RegistryItem::fromArray([
            'code' => 'core.analytics',
            'type' => 'module',
            'vendor' => 'Core',
            'name' => 'Analytics',
            'version' => '1.0.0',
            'artifact' => [
                'url' => $this->artifactUrl,
                'type' => 'zip',
                'sha256' => $this->realArtifactSha256(),
                'size' => 358,
                'signature' => null,
            ],
        ]);

        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry')), config('addons-registry'));
        $result = $downloader->download($item);

        $this->assertFalse($result->success);
    }

    /* -------------------------------------------------------------------------
     | MarketplaceManager::downloadArtifact (integration via registry)
     | ---------------------------------------------------------------------- */

    public function test_marketplace_download_artifact_returns_quarantined(): void
    {
        $this->configureRegistry(true);
        $bytes = $this->realArtifactBytes();
        $this->fakeRegistryAndArtifact($bytes);

        $manager = app(MarketplaceManager::class);
        $result = $manager->downloadArtifact('core.analytics');

        $this->assertTrue($result->success);
        $this->assertSame('quarantined', $result->status);

        $status = $manager->getArtifactStatus('core.analytics');
        $this->assertSame('quarantined', $status['status']);
        $this->assertNotNull($status['path']);
        $this->assertNotNull($status['metadata']);
    }

    public function test_marketplace_download_disabled_blocks(): void
    {
        $this->configureRegistry(false);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        $result = app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->assertFalse($result->success);
        $this->assertSame('downloads_disabled', $result->status);
    }

    public function test_marketplace_download_checksum_mismatch_rejected(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact('modified bytes', $this->realArtifactSha256());

        $result = app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->assertFalse($result->success);
        $this->assertSame('rejected', $result->status);
    }

    public function test_marketplace_does_not_install_artifact_addon(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        // Remote-only addon must NOT appear as installed in system_addons.
        $this->assertDatabaseMissing('system_addons', ['code' => 'core.analytics']);
    }

    /* -------------------------------------------------------------------------
     | CLI addons:download
     | ---------------------------------------------------------------------- */

    public function test_cli_download_command_works(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        $this->artisan(DownloadAddon::class, ['code' => 'core.analytics'])
            ->assertSuccessful()
            ->expectsOutputToContain('завантажено')
            ->expectsOutputToContain($this->realArtifactSha256());
    }

    /* -------------------------------------------------------------------------
     | Marketplace UI
     | ---------------------------------------------------------------------- */

    public function test_marketplace_html_has_download_artifact_wire_click(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $this->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('wire:click="downloadArtifact(\'core.analytics\')"', false)
            ->assertDontSee('@js(');
    }

    public function test_marketplace_download_action_updates_status_to_quarantined(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Marketplace::class)
            ->call('downloadArtifact', 'core.analytics')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('У quarantine');

        $status = app(MarketplaceManager::class)->getArtifactStatus('core.analytics');
        $this->assertSame('quarantined', $status['status']);
    }

    /* -------------------------------------------------------------------------
     | Doctor diagnostics
     | ---------------------------------------------------------------------- */

    public function test_doctor_reports_downloads_disabled_info(): void
    {
        $this->configureRegistry(false);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        $this->artisan('addons:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('addon_artifact_downloads_disabled');
    }

    public function test_doctor_reports_quarantined_remote_only_info(): void
    {
        $this->configureRegistry(true);
        $this->fakeRegistryAndArtifact($this->realArtifactBytes());

        app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->artisan('addons:doctor')
            ->expectsOutputToContain('addon_artifact_quarantined_remote_only');
    }
}
