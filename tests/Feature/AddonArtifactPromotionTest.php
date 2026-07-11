<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\AddonLivePathResolver;
use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactReviewManager;
use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use App\Support\Addons\Registry\ArtifactStagingManager;
use App\Support\Addons\Registry\ArtifactTrustEvaluator;
use App\Support\Addons\Registry\QuarantinedArtifactInspector;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AddonArtifactPromotionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'core.analytics';

    private const VERSION = '1.0.0';

    private string $registryUrl = 'http://127.0.0.1:9001/promotion-registry.json';

    private string $artifactUrl = 'http://127.0.0.1:9001/core.analytics-1.0.0.zip';

    private string $testModulesPath;

    private string $testExtensionsPath;

    private string $signingSecret;

    private string $signingPublic;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('addons');
        $this->testModulesPath = storage_path('app/test-addon-live/modules');
        $this->testExtensionsPath = storage_path('app/test-addon-live/extensions');
        File::deleteDirectory(dirname($this->testModulesPath));
        File::deleteDirectory(dirname($this->testExtensionsPath));
        File::ensureDirectoryExists($this->testModulesPath);
        File::ensureDirectoryExists($this->testExtensionsPath);
        $pair = sodium_crypto_sign_keypair();
        $this->signingSecret = sodium_crypto_sign_secretkey($pair);
        $this->signingPublic = sodium_crypto_sign_publickey($pair);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->testModulesPath));
        File::deleteDirectory(dirname($this->testExtensionsPath));

        parent::tearDown();
    }

    public function test_live_path_resolver_normalizes_module_and_extension_destinations(): void
    {
        Config::set('addons-registry.live_roots.modules_path', $this->testModulesPath);
        Config::set('addons-registry.live_roots.extensions_path', $this->testExtensionsPath);

        $resolver = app(AddonLivePathResolver::class);

        $module = $resolver->resolve([
            'type' => 'module',
            'vendor' => 'Core',
            'code' => 'core.analytics',
        ]);

        $extension = $resolver->resolve([
            'type' => 'extension',
            'vendor' => 'Core',
            'code' => 'core.theme-maker',
        ]);

        $this->assertSame($this->testModulesPath.'/Core/Analytics', $module['live_path']);
        $this->assertSame($this->testExtensionsPath.'/Core/ThemeMaker', $extension['live_path']);
    }

    public function test_live_path_resolver_blocks_traversal_absolute_and_invalid_vendor(): void
    {
        Config::set('addons-registry.live_roots.modules_path', $this->testModulesPath);

        $resolver = app(AddonLivePathResolver::class);

        $this->expectExceptionMessage('unsafe');
        $resolver->resolve([
            'type' => 'module',
            'vendor' => '../../evil',
            'code' => 'core.analytics',
        ]);
    }

    public function test_promotion_disabled_is_blocked(): void
    {
        $this->preparePromotableArtifact();
        Config::set('addons-registry.promotion.enabled', false);

        $result = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertFalse($result->success);
        $this->assertSame('blocked', $result->status);
    }

    public function test_first_promotion_succeeds_without_executing_payload(): void
    {
        $state = $this->preparePromotableArtifact(includeMarker: true);
        Config::set('addons-registry.promotion.enabled', true);

        $markerFile = storage_path('app/promotion-marker.txt');
        File::delete($markerFile);

        $result = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertTrue($result->success, implode(' ', $result->diagnostics));
        $this->assertSame('promoted', $result->status);
        $this->assertNotNull($result->livePath);
        $this->assertTrue(is_dir($result->livePath));
        $this->assertTrue(Storage::disk('addons')->exists($state['staging_path'].'/staging.json'));
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
        $this->assertFalse(File::exists($markerFile));

        $metadata = json_decode(Storage::disk('addons')->get($state['metadata_path']), true);
        $this->assertSame('promoted', $metadata['promotion_status']);
        $this->assertSame($result->livePath, $metadata['promotion_live_path']);
        $this->assertSame('1.0.0', $metadata['promoted_version']);
        $this->assertTrue($result->rollbackAvailable);
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
    }

    public function test_existing_live_addon_is_backed_up_and_rollback_restores_it(): void
    {
        $state = $this->preparePromotableArtifact(version: '1.0.1', includeMarker: false);
        Config::set('addons-registry.promotion.enabled', true);

        $livePath = $this->testModulesPath.'/Core/Analytics';
        File::ensureDirectoryExists($livePath);
        File::put($livePath.'/manifest.json', json_encode([
            'code' => self::CODE,
            'type' => 'module',
            'vendor' => 'Core',
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($livePath.'/legacy.txt', 'old live');

        $promote = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));
        $this->assertTrue($promote->success, implode(' ', $promote->diagnostics));
        $this->assertNotNull($promote->backupPath);
        $this->assertTrue(is_dir($promote->backupPath));
        $this->assertFileExists($promote->backupPath.'/backup.json');

        $backup = json_decode(File::get($promote->backupPath.'/backup.json'), true);
        $this->assertSame('1.0.0', $backup['old_version']);
        $this->assertSame($livePath, $backup['source_live_path']);

        $rollback = app(ArtifactPromotionManager::class)->rollback(self::CODE, $promote->transactionId, 'test rollback', ArtifactReviewActor::cli('test'));
        $this->assertTrue($rollback->success, implode(' ', $rollback->diagnostics));
        $this->assertSame('rolled_back', $rollback->status);
        $this->assertTrue(is_dir($livePath));
        $this->assertSame('1.0.0', json_decode(File::get($livePath.'/manifest.json'), true)['version']);
        $this->assertTrue(Storage::disk('addons')->exists($state['staging_path'].'/staging.json'));
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
    }

    public function test_promotion_blocks_when_lock_is_held(): void
    {
        $this->preparePromotableArtifact();
        Config::set('addons-registry.promotion.enabled', true);

        $lock = Cache::lock('addons:promotion:'.self::CODE, 30);
        $this->assertTrue($lock->get());

        try {
            $result = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));
            $this->assertFalse($result->success);
            $this->assertSame('blocked', $result->status);
        } finally {
            $lock->release();
        }
    }

    public function test_promotion_blocks_when_staged_file_changes(): void
    {
        $state = $this->preparePromotableArtifact();
        Config::set('addons-registry.promotion.enabled', true);

        Storage::disk('addons')->put($state['staging_path'].'/payload/README.md', 'tampered');

        $result = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertFalse($result->success);
        $this->assertSame('blocked', $result->status);
        $this->assertContains('Staging integrity validation failed.', $result->blockedReasons);
    }

    public function test_rollback_blocks_if_live_tree_changes_manually(): void
    {
        $state = $this->preparePromotableArtifact(version: '1.0.1');
        Config::set('addons-registry.promotion.enabled', true);

        $promote = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::cli('test'));
        $this->assertTrue($promote->success);

        File::put($promote->livePath.'/manual.txt', 'changed');

        $rollback = app(ArtifactPromotionManager::class)->rollback(self::CODE, $promote->transactionId, null, ArtifactReviewActor::cli('test'));
        $this->assertFalse($rollback->success);
        $this->assertSame('blocked', $rollback->status);
        $this->assertTrue(Storage::disk('addons')->exists($state['staging_path'].'/staging.json'));
    }

    private function preparePromotableArtifact(string $version = self::VERSION, bool $includeMarker = false): array
    {
        $registryUrl = $this->registryUrl.'?version='.$version.'&request='.uniqid('', true);
        $bytes = $this->artifactBytes($version, $includeMarker);
        $signature = base64_encode(sodium_crypto_sign_detached($bytes, $this->signingSecret));

        $artifact = [
            'url' => $this->artifactUrlFor($version),
            'type' => 'zip',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'signature' => [
                'type' => 'ed25519',
                'value' => $signature,
                'key_id' => 'review-key',
            ],
        ];
        $registry = [
            'registry' => ['name' => 'promotion-test', 'version' => '1.0.0'],
            'items' => [[
                'code' => self::CODE,
                'type' => 'module',
                'vendor' => 'Core',
                'name' => 'Analytics',
                'description' => 'Promotion test',
                'version' => $version,
                'artifact' => $artifact,
            ]],
        ];

        Http::fake([$registryUrl => Http::response($registry)]);
        Config::set('addons-registry.enabled', true);
        Config::set('addons-registry.url', $registryUrl);
        Config::set('addons-registry.allow_localhost', true);
        Config::set('addons-registry.mode', 'read_only');
        Config::set('addons-registry.trust.require_signature', true);
        Config::set('addons-registry.trust.trusted_keys', ['review-key' => base64_encode($this->signingPublic)]);
        Config::set('addons-registry.downloads.disk', 'addons');
        Config::set('addons-registry.downloads.quarantine_path', 'addons/quarantine');
        Config::set('addons-registry.review.enabled', true);
        Config::set('addons-registry.review.require_trusted', true);
        Config::set('addons-registry.review.require_note_on_reject', true);
        Config::set('addons-registry.review.allow_revoke', true);
        Config::set('addons-registry.staging.enabled', true);
        Config::set('addons-registry.promotion.enabled', true);
        Config::set('addons-registry.live_roots.modules_path', $this->testModulesPath);
        Config::set('addons-registry.live_roots.extensions_path', $this->testExtensionsPath);

        $directory = 'addons/quarantine/'.self::CODE.'/'.$version;
        Storage::disk('addons')->put($directory.'/core.analytics-'.$version.'.zip', $bytes);
        Storage::disk('addons')->put($directory.'/metadata.json', json_encode([
            'code' => self::CODE,
            'version' => $version,
            'source_url' => $artifact['url'],
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'downloaded_at' => now()->toIso8601String(),
            'status' => 'quarantined',
            'signature_status' => 'valid',
            'signature_checked_at' => now()->toIso8601String(),
            'signature_key_id' => 'review-key',
            'manifest_status' => 'valid',
            'manifest_checked_at' => now()->toIso8601String(),
            'trust_status' => 'trusted',
            'review_status' => 'pending',
            'reviewed_at' => null,
            'reviewed_by' => null,
            'reviewed_by_name' => null,
            'review_note' => null,
            'approval_revoked_at' => null,
            'approval_revoked_by' => null,
            'approval_revoked_by_name' => null,
            'approval_revoke_note' => null,
            'review_history' => [],
            'approved_integrity_snapshot' => null,
            'approval_is_stale' => false,
            'staging_status' => 'not_staged',
            'staging_path' => null,
            'staged_at' => null,
            'staged_by' => null,
            'staged_by_name' => null,
            'staging_artifact_sha256' => null,
            'staging_inventory_hash' => null,
            'staging_diagnostics' => [],
            'staging_is_stale' => false,
            'promotion_status' => 'not_promoted',
            'promotion_transaction_id' => null,
            'promotion_live_path' => null,
            'promotion_backup_path' => null,
            'promoted_at' => null,
            'promoted_by' => null,
            'promoted_by_name' => null,
            'promoted_by_type' => null,
            'promoted_version' => null,
            'promotion_inventory_hash' => null,
            'promotion_diagnostics' => [],
            'promotion_is_stale' => false,
            'rollback_available' => false,
            'last_rollback_transaction_id' => null,
            'artifact_diagnostics' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        app()->forgetInstance(RegistryClient::class);
        app()->forgetInstance(RegistryCatalog::class);
        app()->forgetInstance(ArtifactReviewManager::class);
        app()->forgetInstance(ArtifactSignatureVerifier::class);
        app()->forgetInstance(ArtifactTrustEvaluator::class);
        app()->forgetInstance(QuarantinedArtifactInspector::class);
        app()->singleton(RegistryClient::class, fn () => new RegistryClient(config('addons-registry')));
        app()->singleton(RegistryCatalog::class, fn ($app) => new RegistryCatalog(
            $app->make(RegistryClient::class),
            config('addons-registry'),
        ));
        app(RegistryCatalog::class)->flush();

        $review = app(ArtifactReviewManager::class);
        $stage = app(ArtifactStagingManager::class);
        $actor = ArtifactReviewActor::cli('test');
        $this->assertTrue($review->approve(self::CODE, 'approve', $actor)->success);
        $stageResult = $stage->stage(self::CODE, $actor);
        $this->assertTrue($stageResult->success, implode(' ', $stageResult->diagnostics));

        return [
            'metadata_path' => $directory.'/metadata.json',
            'staging_path' => $stageResult->stagingPath,
            'metadata' => json_decode(Storage::disk('addons')->get($directory.'/metadata.json'), true),
        ];
    }

    private function artifactBytes(string $version, bool $includeMarker): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'addon-promotion-');
        if ($zipPath === false) {
            $this->fail('Unable to create temp artifact.');
        }
        @unlink($zipPath);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->fail('Unable to create zip artifact.');
        }

        $zip->addFromString('manifest.json', json_encode([
            'code' => self::CODE,
            'type' => 'module',
            'name' => 'Analytics',
            'description' => 'Promotion test',
            'version' => $version,
            'vendor' => 'Core',
            'author' => 'Alta Trade',
            'enabled_by_default' => false,
            'service_provider' => null,
            'dependencies' => [],
            'settings_schema' => [],
            'compatibility' => [
                'app_min_version' => null,
                'app_max_version' => null,
                'laravel_version' => '>=12.0',
                'php_version' => '>=8.3',
            ],
            'permissions' => [],
            'menu' => [],
            'migrations' => [],
            'seeders' => [],
            'routes' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.md', 'Promotion test addon.');

        if ($includeMarker) {
            $zip->addFromString('src/BootMarker.php', <<<'PHP'
<?php

file_put_contents(storage_path('app/promotion-marker.txt'), 'booted');
PHP);
        }

        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return $bytes;
    }

    private function artifactUrlFor(string $version): string
    {
        return 'http://127.0.0.1:9001/core.analytics-'.$version.'.zip';
    }

}