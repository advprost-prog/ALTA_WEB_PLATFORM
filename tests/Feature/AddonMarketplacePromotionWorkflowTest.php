<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Policies\AddonArtifactPromotionPolicy;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactReviewManager;
use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use App\Support\Addons\Registry\ArtifactStagingManager;
use App\Support\Addons\Registry\ArtifactTrustEvaluator;
use App\Support\Addons\Registry\QuarantinedArtifactInspector;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use App\Support\Addons\Registry\VerifiedAddonInstallOrchestrator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonMarketplacePromotionWorkflowTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private const CODE = 'core.analytics';

    private string $registryBaseUrl = 'http://127.0.0.1:9001';

    private string $testModulesPath;

    private string $testExtensionsPath;

    private string $signingSecret;

    private string $signingPublic;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('addons');
        $this->testModulesPath = storage_path('app/test-addon-live-ui/modules');
        $this->testExtensionsPath = storage_path('app/test-addon-live-ui/extensions');
        File::deleteDirectory(dirname($this->testModulesPath));
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

        parent::tearDown();
    }

    public function test_marketplace_resolve_includes_promotion_fields(): void
    {
        $this->preparePromotableArtifact();

        $row = $this->resolvedRow();

        foreach ([
            'promotion_enabled',
            'promotion_status',
            'promotion_label',
            'promotion_color',
            'promotion_transaction_id',
            'promotion_live_path',
            'promotion_backup_path',
            'promoted_version',
            'promoted_at',
            'promoted_by',
            'promoted_by_name',
            'promoted_by_type',
            'promotion_inventory_hash',
            'promotion_source_artifact_sha256',
            'promotion_is_stale',
            'rollback_available',
            'last_rollback_transaction_id',
            'live_inventory_matches',
            'idempotent_ready',
            'current_live_inventory_hash',
            'promotion_diagnostics',
            'can_promote',
            'can_rollback',
            'promotion_blocked_reasons',
        ] as $field) {
            $this->assertArrayHasKey($field, $row);
        }

        $this->assertTrue($row['promotion_enabled']);
        $this->assertSame('ready', $row['promotion_status']);
        $this->assertTrue($row['can_promote']);
        $this->assertFalse($row['can_rollback']);
    }

    public function test_promotion_policy_allows_admin_denies_manager_and_supports_optional_super_admin(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $manager = $this->createUserWithRole(UserRole::Manager);

        $this->assertTrue(AddonArtifactPromotionPolicy::canPromote($admin));
        $this->assertTrue(AddonArtifactPromotionPolicy::canRollback($admin));
        $this->assertFalse(AddonArtifactPromotionPolicy::canPromote($manager));
        $this->assertFalse(AddonArtifactPromotionPolicy::canRollback($manager));

        DB::table('users')->where('id', $manager->id)->update(['role' => 'super_admin']);
        $manager->refresh();

        $this->assertTrue(AddonArtifactPromotionPolicy::canPromote($manager));
        $this->assertTrue(AddonArtifactPromotionPolicy::canRollback($manager));
    }

    public function test_admin_can_promote_from_marketplace_modal_without_lifecycle_execution(): void
    {
        $state = $this->preparePromotableArtifact(includeMarker: true);
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $lw = $this->marketplace();
        $lw->call('openPromoteArtifactModal', self::CODE)
            ->assertSet('promotionModalOpen', true)
            ->assertSee('Перенести artifact у live directory')
            ->assertSee('Code: core.analytics')
            ->assertSee('Type: module')
            ->assertSee('Addon не буде автоматично discovered, installed або enabled');

        $this->assertStringNotContainsString('confirm(', $lw->html());
        $this->assertStringNotContainsString('@js', $lw->html());

        File::delete(storage_path('app/promotion-marker.txt'));

        $lw->call('promoteArtifact')
            ->assertSet('promotionModalOpen', false);

        $metadata = $this->metadata($state['metadata_path']);
        $this->assertSame('promoted', $metadata['promotion_status']);
        $this->assertStringStartsWith($this->testModulesPath, (string) $metadata['promotion_live_path']);
        $this->assertTrue(is_dir((string) $metadata['promotion_live_path']));
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
        $this->assertTrue(Storage::disk('addons')->exists($state['staging_path'].'/staging.json'));
        $this->assertSame(0, DB::table('system_addons')->where('code', self::CODE)->count());
        $this->assertFalse(app()->bound(self::CODE.'.booted'));
        $this->assertFalse(File::exists(storage_path('app/promotion-marker.txt')));
    }

    public function test_non_admin_cannot_open_or_execute_promotion_or_rollback(): void
    {
        $state = $this->preparePromotableArtifact();

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);
        app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));

        $metadataBefore = Storage::disk('addons')->get($state['metadata_path']);
        $journalBefore = count(Storage::disk('addons')->allFiles('addons/promotion-journal/core.analytics'));
        $backupBefore = count(Storage::disk('addons')->directories('addons/backups/core.analytics'));

        $manager = $this->createUserWithRole(UserRole::Manager);
        $this->actingAs($manager);

        $this->get('/admin/marketplace')
            ->assertOk()
            ->assertDontSee('openPromoteArtifactModal(\'core.analytics\')', false)
            ->assertDontSee('openRollbackArtifactModal(\'core.analytics\')', false);

        Livewire::test(Marketplace::class)
            ->call('openPromoteArtifactModal', self::CODE)
            ->set('promotionArtifactCode', self::CODE)
            ->call('promoteArtifact')
            ->set('promotionArtifactCode', self::CODE)
            ->set('promotionTransactionId', $this->metadata($state['metadata_path'])['promotion_transaction_id'])
            ->call('rollbackArtifact');

        $this->assertSame($metadataBefore, Storage::disk('addons')->get($state['metadata_path']));
        $this->assertSame($journalBefore, count(Storage::disk('addons')->allFiles('addons/promotion-journal/core.analytics')));
        $this->assertSame($backupBefore, count(Storage::disk('addons')->directories('addons/backups/core.analytics')));
    }

    public function test_idempotent_ui_state_hides_promote_action_and_direct_repeat_is_noop(): void
    {
        $state = $this->preparePromotableArtifact();

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);
        $first = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));

        $row = $this->resolvedRow();
        $this->assertSame('promoted', $row['promotion_status']);
        $this->assertTrue((bool) ($row['idempotent_ready'] ?? false));
        $this->assertTrue((bool) ($row['live_inventory_matches'] ?? false));

        $html = $this->marketplace()->html();
        $this->assertStringNotContainsString('openPromoteArtifactModal(\'core.analytics\')', $html);

        $metadataBefore = $this->metadata($state['metadata_path']);
        $journalBefore = count(Storage::disk('addons')->allFiles('addons/promotion-journal/core.analytics'));
        $backupBefore = count(Storage::disk('addons')->directories('addons/backups/core.analytics'));

        Livewire::test(Marketplace::class)
            ->set('promotionArtifactCode', self::CODE)
            ->call('promoteArtifact');

        $metadataAfter = $this->metadata($state['metadata_path']);
        $this->assertSame($first->transactionId, $metadataAfter['promotion_transaction_id']);
        $this->assertSame($metadataBefore['promoted_at'], $metadataAfter['promoted_at']);
        $this->assertSame($journalBefore, count(Storage::disk('addons')->allFiles('addons/promotion-journal/core.analytics')));
        $this->assertSame($backupBefore, count(Storage::disk('addons')->directories('addons/backups/core.analytics')));
    }

    public function test_live_drift_warning_renders_and_repeat_promotion_is_blocked(): void
    {
        $state = $this->preparePromotableArtifact();
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $promoted = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));
        File::put($promoted->livePath.'/README.md', 'manual drift by UI test');

        $row = $this->resolvedRow();
        $this->assertTrue((bool) ($row['promotion_is_stale'] ?? false) || ! (bool) ($row['live_inventory_matches'] ?? true));
        $this->assertContains('artifact_promotion_live_fingerprint_mismatch', $this->diagnosticCodes((array) ($row['promotion_diagnostics'] ?? [])));

        $html = $this->marketplace()->html();
        $this->assertStringNotContainsString('openPromoteArtifactModal(\'core.analytics\')', $html);

        Livewire::test(Marketplace::class)
            ->set('promotionArtifactCode', self::CODE)
            ->call('promoteArtifact');

        $this->assertSame('manual drift by UI test', File::get($promoted->livePath.'/README.md'));
        $this->assertSame('promoted', $this->metadata($state['metadata_path'])['promotion_status']);
    }

    public function test_first_install_rollback_modal_flow_removes_live_and_keeps_quarantine_staging(): void
    {
        $state = $this->preparePromotableArtifact();
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $promoted = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));
        $this->assertTrue($promoted->success);

        Livewire::test(Marketplace::class)
            ->call('openRollbackArtifactModal', self::CODE)
            ->assertSet('promotionModalOpen', true)
            ->assertSee('Відкотити перенесення artifact')
            ->assertSee('Попередню live-версію буде відновлено з перевіреного backup')
            ->assertSee('Rollback не запускає discover/install/enable, provider execution, migrations, composer або npm.')
            ->call('rollbackArtifact')
            ->assertSet('promotionModalOpen', false);

        $metadata = $this->metadata($state['metadata_path']);
        $this->assertSame('rolled_back', $metadata['promotion_status']);
        $this->assertFalse(is_dir($promoted->livePath));
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
        $this->assertTrue(Storage::disk('addons')->exists($state['staging_path'].'/staging.json'));
        $this->assertSame('approved', $metadata['review_status']);
    }

    public function test_update_rollback_restores_previous_live_version(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $v1 = $this->preparePromotableArtifact(version: '1.0.0');
        $first = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));
        $this->assertTrue($first->success);

        $v11 = $this->preparePromotableArtifact(version: '1.1.0');
        $second = app(ArtifactPromotionManager::class)->promote(self::CODE, ArtifactReviewActor::fromUser($admin));
        $this->assertTrue($second->success);
        $this->assertNotSame($first->transactionId, $second->transactionId);
        $this->assertNotNull($second->backupPath);

        Livewire::test(Marketplace::class)
            ->call('openRollbackArtifactModal', self::CODE)
            ->set('promotionTransactionId', $second->transactionId)
            ->call('rollbackArtifact');

        $manifest = json_decode(File::get($first->livePath.'/manifest.json'), true);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertTrue(Storage::disk('addons')->exists($v1['staging_path'].'/staging.json'));
        $this->assertTrue(Storage::disk('addons')->exists($v11['staging_path'].'/staging.json'));
    }

    public function test_marketplace_cli_json_contains_promotion_payload_fields(): void
    {
        $this->preparePromotableArtifact();

        Artisan::call('addons:marketplace', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $item = collect($payload['items'])->first(fn (array $row): bool => $row['code'] === self::CODE);
        $this->assertNotNull($item);

        foreach ([
            'promotion_status',
            'promotion_transaction_id',
            'promotion_live_path',
            'promotion_backup_path',
            'promoted_version',
            'promoted_at',
            'promoted_by_name',
            'promotion_is_stale',
            'rollback_available',
            'live_inventory_matches',
            'idempotent_ready',
            'can_promote',
            'can_rollback',
            'promotion_blocked_reasons',
        ] as $field) {
            $this->assertArrayHasKey($field, $item);
        }
    }

    public function test_verified_first_install_orchestration_promotes_discovers_and_registers_disabled(): void
    {
        $state = $this->preparePromotableArtifact();
        $result = app(VerifiedAddonInstallOrchestrator::class)->execute(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertTrue($result->success, implode(' ', $result->diagnostics));
        $this->assertSame('completed', $result->state);
        $this->assertSame('install', $result->operationType);
        $addon = DB::table('system_addons')->where('code', self::CODE)->first();
        $this->assertNotNull($addon);
        $this->assertSame('1.0.0', $addon->version);
        $this->assertFalse((bool) $addon->is_enabled);
        $this->assertTrue((bool) $addon->is_installed);
        $this->assertTrue(Storage::disk('addons')->exists($state['metadata_path']));
        $this->assertFalse(Storage::disk('addons')->exists($state['staging_path']));
        $this->assertCount(1, Storage::disk('addons')->allFiles('addons/install-journal/'.self::CODE));
        $this->assertFalse(app()->bound(self::CODE.'.booted'));
    }

    public function test_verified_update_preserves_enabled_intent_and_retains_backup_without_boot(): void
    {
        $this->preparePromotableArtifact('1.0.0');
        $first = app(VerifiedAddonInstallOrchestrator::class)->execute(self::CODE, ArtifactReviewActor::cli('test'), true);
        $this->assertTrue($first->success);
        $this->assertTrue($first->enabled);

        $this->preparePromotableArtifact('1.1.0');
        $second = app(VerifiedAddonInstallOrchestrator::class)->execute(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertTrue($second->success, implode(' ', $second->diagnostics));
        $this->assertSame('update', $second->operationType);
        $this->assertSame('1.1.0', DB::table('system_addons')->where('code', self::CODE)->value('version'));
        $this->assertTrue((bool) DB::table('system_addons')->where('code', self::CODE)->value('is_enabled'));
        $this->assertNotEmpty(Storage::disk('addons')->directories('addons/backups/'.self::CODE));
        $this->assertFalse(app()->bound(self::CODE.'.booted'));
    }

    public function test_discovery_failure_compensates_first_install_without_partial_local_state(): void
    {
        $this->preparePromotableArtifact();
        $this->mock(AddonDiscovery::class)
            ->shouldReceive('syncManifest')
            ->once()
            ->andThrow(new \RuntimeException('injected discovery failure'));

        $result = app(VerifiedAddonInstallOrchestrator::class)->execute(self::CODE, ArtifactReviewActor::cli('test'));

        $this->assertFalse($result->success);
        $this->assertTrue($result->rolledBack);
        $this->assertSame('post_install_verification_failed', $result->failureCode);
        $this->assertSame(0, DB::table('system_addons')->where('code', self::CODE)->count());
        $metadata = collect(Storage::disk('addons')->allFiles('addons/quarantine/'.self::CODE))->first(fn (string $path) => str_ends_with($path, 'metadata.json'));
        $this->assertNotNull($metadata);
        $this->assertFalse(app()->bound(self::CODE.'.booted'));
    }

    private function marketplace(): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(Marketplace::class);
    }

    /**
     * @return array{metadata_path: string, staging_path: string}
     */
    private function preparePromotableArtifact(string $version = '1.0.0', bool $includeMarker = false): array
    {
        $registryUrl = $this->registryBaseUrl.'/promotion-ui-registry-'.$version.'.json?request='.uniqid('', true);
        $bytes = $this->artifactBytes($version, $includeMarker);
        $signature = base64_encode(sodium_crypto_sign_detached($bytes, $this->signingSecret));

        $artifact = [
            'url' => $this->registryBaseUrl.'/core.analytics-'.$version.'.zip',
            'type' => 'zip',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'signature' => [
                'type' => 'ed25519',
                'value' => $signature,
                'key_id' => 'review-key',
                'payload_version' => 'raw-zip-v1',
            ],
        ];

        Http::fake([$registryUrl => Http::response([
            'registry' => ['name' => 'promotion-ui-test', 'version' => 'test-build', 'application_version' => '1.0.0', 'build_version' => 'test-build', 'schema_version' => '1', 'generated_at' => '2026-07-14T00:00:00+00:00'],
            'items' => [[
                'code' => self::CODE,
                'type' => 'module',
                'vendor' => 'Core',
                'name' => 'Analytics',
                'description' => 'Promotion UI test',
                'version' => $version,
                'category' => null, 'tags' => [], 'requires_platform' => null, 'dependencies' => [], 'is_featured' => false,
                'homepage_url' => null, 'documentation_url' => null,
                'publisher' => ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Test'],
                'published_at' => '2026-07-14T00:00:00+00:00',
                'artifact' => $artifact,
            ]],
        ])]);

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
        $artifactPath = $directory.'/core.analytics-'.$version.'.zip';
        $metadataPath = $directory.'/metadata.json';

        Storage::disk('addons')->put($artifactPath, $bytes);
        Storage::disk('addons')->put($metadataPath, json_encode([
            'code' => self::CODE,
            'version' => $version,
            'source_url' => $artifact['url'],
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'downloaded_at' => now()->toIso8601String(),
            'status' => 'quarantined',
            'verification_state' => 'verified',
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

        $actor = ArtifactReviewActor::cli('test');
        $review = app(ArtifactReviewManager::class);
        $stage = app(ArtifactStagingManager::class);
        $this->assertTrue($review->approve(self::CODE, 'approve', $actor)->success);
        $stageResult = $stage->stage(self::CODE, $actor);
        $this->assertTrue($stageResult->success, implode(' ', $stageResult->diagnostics));

        return [
            'metadata_path' => $metadataPath,
            'staging_path' => $stageResult->stagingPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $path): array
    {
        return json_decode(Storage::disk('addons')->get($path), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedRow(): array
    {
        $resolved = app(MarketplaceManager::class)->resolve();

        return collect($resolved['rows'])->first(fn (array $row): bool => $row['item']->code === self::CODE);
    }

    private function artifactBytes(string $version, bool $includeMarker): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'addon-promotion-ui-');
        if ($zipPath === false) {
            $this->fail('Unable to create temp artifact.');
        }
        @unlink($zipPath);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->fail('Unable to create zip artifact.');
        }

        $zip->addFromString('manifest.json', json_encode([
            'code' => self::CODE,
            'type' => 'module',
            'name' => 'Analytics',
            'description' => 'Promotion UI test',
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

        $zip->addFromString('README.md', 'Promotion UI test addon.');

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

    /**
     * @param  array<int, mixed>  $diagnostics
     * @return array<int, string>
     */
    private function diagnosticCodes(array $diagnostics): array
    {
        $codes = [];

        foreach ($diagnostics as $diagnostic) {
            if (is_array($diagnostic) && isset($diagnostic['code'])) {
                $codes[] = (string) $diagnostic['code'];
            }
        }

        return $codes;
    }
}
