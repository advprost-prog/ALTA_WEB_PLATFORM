<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Policies\AddonArtifactStagingPolicy;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactReviewManager;
use App\Support\Addons\Registry\ArtifactReviewStatus;
use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use App\Support\Addons\Registry\ArtifactStagingManager;
use App\Support\Addons\Registry\ArtifactTrustEvaluator;
use App\Support\Addons\Registry\QuarantinedArtifactInspector;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonArtifactReviewCoreTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private const CODE = 'core.analytics';

    private const VERSION = '1.0.0';

    private string $registryUrl = 'http://127.0.0.1:9001/review-registry.json';

    private string $artifactUrl = 'http://127.0.0.1:9001/api/v1/artifacts/review-artifact/download';

    private string $metadataPath = 'addons/quarantine/core.analytics/1.0.0/metadata.json';

    private string $signingSecret;

    private string $signingPublic;

    private int $registryRequest = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('addons');
        $keypair = sodium_crypto_sign_keypair();
        $this->signingSecret = sodium_crypto_sign_secretkey($keypair);
        $this->signingPublic = sodium_crypto_sign_publickey($keypair);
    }

    public function test_trusted_artifact_approval_snapshot_and_staleness_contract(): void
    {
        $manager = $this->prepareArtifact();
        $result = $manager->approve(self::CODE, 'reviewed', ArtifactReviewActor::cli('test'));

        $this->assertTrue($result->success);
        $this->assertSame(ArtifactReviewStatus::APPROVED, $result->reviewStatus);

        $metadata = $this->metadata();
        $snapshot = $metadata['approved_integrity_snapshot'];
        $this->assertSame(self::CODE, $snapshot['code']);
        $this->assertSame(self::VERSION, $snapshot['version']);
        $this->assertSame(strlen($this->artifactBytes()), $snapshot['size']);
        $this->assertSame('review-key', $snapshot['signature_key_id']);
        $this->assertSame('valid', $snapshot['signature_status']);
        $this->assertSame('valid', $snapshot['manifest_status']);
        $this->assertSame(hash('sha256', $this->artifactBytes()), $snapshot['sha256']);
        $this->assertSame('cli', $metadata['reviewed_by']);
        $this->assertSame('CLI (test)', $metadata['reviewed_by_name']);
        $this->assertSame('reviewed', $metadata['review_note']);
        $this->assertSame(['approved'], array_column($metadata['review_history'], 'action'));
        $this->assertFalse($manager->getReviewReport(self::CODE)['report']['approval_is_stale']);

        foreach ([
            'code' => 'changed',
            'version' => 'changed',
            'sha256' => 'changed',
            'size' => 1,
            'signature_key_id' => 'changed',
            'signature_status' => 'changed',
            'manifest_status' => 'changed',
        ] as $field => $value) {
            $changed = $metadata;
            $changed['approved_integrity_snapshot'][$field] = $value;
            $this->writeMetadata($changed);

            $this->assertTrue(
                $manager->getReviewReport(self::CODE)['report']['approval_is_stale'],
                "Changing {$field} must make approval stale.",
            );
        }

        $legacy = $metadata;
        unset($legacy['approved_integrity_snapshot']['code']);
        $this->writeMetadata($legacy);
        $this->assertTrue($manager->getReviewReport(self::CODE)['report']['approval_is_stale']);
    }

    public function test_untrusted_artifact_is_blocked_and_reject_requires_note(): void
    {
        $manager = $this->prepareArtifact(false);
        $blocked = $manager->approve(self::CODE, null, ArtifactReviewActor::cli('test'));

        $this->assertFalse($blocked->success);
        $this->assertSame('blocked', $blocked->status);
        $this->assertNotEmpty($blocked->diagnostics);

        $manager = $this->prepareArtifact();
        $rejected = $manager->reject(self::CODE, '', ArtifactReviewActor::cli('test'));
        $this->assertFalse($rejected->success);
        $this->assertSame('blocked', $rejected->status);
    }

    public function test_revoke_preserves_append_only_history_and_remote_only_event_does_not_fail(): void
    {
        $manager = $this->prepareArtifact();
        $actor = ArtifactReviewActor::cli('test');

        $this->assertTrue($manager->approve(self::CODE, 'approve', $actor)->success);
        $this->assertTrue($manager->revoke(self::CODE, 'revoke', $actor)->success);

        $metadata = $this->metadata();
        $this->assertSame(ArtifactReviewStatus::REVOKED, $metadata['review_status']);
        $this->assertSame(['approved', 'revoked'], array_column($metadata['review_history'], 'action'));
    }

    public function test_review_state_transition_guards(): void
    {
        $actor = ArtifactReviewActor::cli('test');
        $manager = $this->prepareArtifact();

        $this->assertFalse($manager->revoke(self::CODE, null, $actor)->success);
        $this->assertTrue($manager->reject(self::CODE, 'reject pending', $actor)->success);

        $manager = $this->prepareArtifact();
        $approval = $manager->approve(self::CODE, null, $actor);
        $this->assertTrue($approval->success, implode(' ', $approval->diagnostics));
        $this->assertFalse($manager->approve(self::CODE, null, $actor)->success);
        $this->assertFalse($manager->reject(self::CODE, 'cannot reject approved', $actor)->success);
        $this->assertTrue($manager->revoke(self::CODE, null, $actor)->success);
        $this->assertTrue($manager->reject(self::CODE, 'reject revoked', $actor)->success);

        $manager = $this->prepareArtifact();
        $this->assertTrue($manager->approve(self::CODE, null, $actor)->success);
        config(['addons-registry.review.allow_revoke' => false]);
        $this->assertFalse($manager->revoke(self::CODE, null, $actor)->success);
    }

    public function test_malformed_history_is_safe_and_diagnostic(): void
    {
        $manager = $this->prepareArtifact();
        $metadata = $this->metadata();
        $metadata['review_history'] = 'invalid';
        $this->writeMetadata($metadata);

        $report = $manager->getReviewReport(self::CODE)['report'];
        $this->assertSame([], $report['review_history']);
        $this->assertTrue($report['review_history_malformed']);
        $this->assertNotEmpty($report['diagnostics']);
        $this->assertTrue($manager->approve(self::CODE, null, ArtifactReviewActor::cli('test'))->success);
    }

    public function test_review_policy_allows_admin_and_denies_non_admin(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $manager = $this->createUserWithRole(UserRole::Manager);

        $this->assertTrue($admin->can('review-addon-artifacts'));
        $this->assertFalse($manager->can('review-addon-artifacts'));
    }

    public function test_cli_review_commands_and_inspect_review_output(): void
    {
        $this->prepareArtifact();
        $this->artisan('addons:approve-artifact '.self::CODE.' --note="CLI approved"')
            ->assertSuccessful()
            ->expectsOutputToContain('Review status: approved');
        $this->artisan('addons:inspect-artifact '.self::CODE)
            ->assertSuccessful()
            ->expectsOutputToContain('reviewed_by:')
            ->expectsOutputToContain('review_history:');
        $this->artisan('addons:revoke-artifact '.self::CODE.' --note="CLI revoked"')->assertSuccessful();
        $this->artisan('addons:reject-artifact '.self::CODE)->assertFailed();
        $this->artisan('addons:reject-artifact '.self::CODE.' --note="CLI rejected"')->assertSuccessful();

        $this->prepareArtifact(false);
        $this->artisan('addons:approve-artifact '.self::CODE)->assertFailed();
        $this->artisan('addons:revoke-artifact '.self::CODE)->assertFailed();
    }

    public function test_livewire_review_modal_and_non_admin_direct_action_guard(): void
    {
        $this->prepareArtifact();
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        Livewire::test(Marketplace::class)
            ->call('openApproveArtifactModal', self::CODE)
            ->assertSet('reviewModalOpen', true)
            ->set('reviewNote', 'Browser approved')
            ->call('approveArtifact')
            ->assertSet('reviewModalOpen', false);
        $this->assertSame('approved', $this->metadata()['review_status']);

        $before = Storage::disk('addons')->get($this->metadataPath);
        $this->actingAs($this->createUserWithRole(UserRole::Manager));
        Livewire::test(Marketplace::class)
            ->set('reviewingArtifactCode', self::CODE)
            ->set('reviewNote', 'unauthorized')
            ->call('revokeArtifactApproval');
        $this->assertSame($before, Storage::disk('addons')->get($this->metadataPath));
    }

    public function test_marketplace_review_markup_is_compact_complete_and_safe(): void
    {
        $this->prepareArtifact();
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $html = Livewire::test(Marketplace::class)->html();
        $markup = file_get_contents(resource_path('views/filament/pages/marketplace.blade.php'));

        $this->assertStringContainsString('fi-ta-table', $html);
        $this->assertStringContainsString('addon-marketplace-artifact__statuses', $markup);
        $this->assertStringContainsString('addon-marketplace-artifact__actions', $markup);
        $this->assertStringNotContainsString('addon-marketplace-card__artifact', $markup);
        $this->assertStringContainsString('Підпис дійсний', $markup);
        $this->assertStringContainsString("'valid' => 'валідний'", $markup);
        $this->assertStringContainsString('Довірений', $markup);
        $this->assertSame('Очікує перевірки', ArtifactReviewStatus::label(ArtifactReviewStatus::PENDING));
        $this->assertStringContainsString('inspectArtifact', $markup);
        $this->assertStringContainsString('openApproveArtifactModal', $markup);
        $this->assertStringContainsString('openRejectArtifactModal', $markup);
        $this->assertStringContainsString('openRevokeArtifactModal', $markup);
        $this->assertStringNotContainsString('@js', $markup);
        $this->assertStringNotContainsString('installAddon(\'core.analytics\')', $html);

        Livewire::test(Marketplace::class)
            ->call('openApproveArtifactModal', self::CODE)
            ->assertSee('addon-marketplace-review-modal', false)
            ->assertSee('Підтвердити схвалення');
    }

    public function test_container_resolves_review_and_marketplace_services(): void
    {
        $this->assertInstanceOf(ArtifactReviewManager::class, app(ArtifactReviewManager::class));
        $this->assertInstanceOf(MarketplaceManager::class, app(MarketplaceManager::class));
    }

    public function test_approved_artifact_can_be_staged_idempotently_and_unstaged_safely(): void
    {
        $review = $this->prepareArtifact();
        $this->assertTrue($review->approve(self::CODE, 'stage', ArtifactReviewActor::cli('test'))->success);
        config(['addons-registry.staging.enabled' => true]);

        $manager = app(ArtifactStagingManager::class);
        $first = $manager->stage(self::CODE, ArtifactReviewActor::cli('test'));
        $this->assertTrue($first->success, implode(' ', $first->diagnostics));
        $this->assertSame('staged', $this->metadata()['staging_status']);
        $this->assertTrue(Storage::disk('addons')->exists($first->stagingPath.'/staging.json'));
        $this->assertTrue(Storage::disk('addons')->exists($this->metadataPath));

        $summaryLost = $this->metadata();
        $summaryLost['staging_status'] = 'not_staged';
        $summaryLost['staging_path'] = null;
        $this->writeMetadata($summaryLost);
        $second = $manager->stage(self::CODE, ArtifactReviewActor::cli('test'));
        $this->assertTrue($second->success);
        $this->assertSame($first->stagingPath, $second->stagingPath);
        $this->assertSame('staged', $this->metadata()['staging_status']);

        $unstaged = $manager->unstage(self::CODE, 'cleanup', ArtifactReviewActor::cli('test'));
        $this->assertTrue($unstaged->success);
        $this->assertFalse(Storage::disk('addons')->exists($first->stagingPath));
        $this->assertTrue(Storage::disk('addons')->exists($this->metadataPath));
        $this->assertSame('approved', $this->metadata()['review_status']);
    }

    public function test_staging_policy_and_livewire_workflow_are_authorized(): void
    {
        $review = $this->prepareArtifact();
        $admin = $this->createUserWithRole(UserRole::Admin);
        $managerUser = $this->createUserWithRole(UserRole::Manager);
        $this->assertTrue(AddonArtifactStagingPolicy::canManage($admin));
        $this->assertFalse(AddonArtifactStagingPolicy::canManage($managerUser));
        $this->assertTrue($admin->can('stage-addon-artifacts'));
        $this->assertFalse($managerUser->can('stage-addon-artifacts'));

        $this->assertTrue($review->approve(self::CODE, 'stage UI', ArtifactReviewActor::cli('test'))->success);
        config(['addons-registry.staging.enabled' => true]);
        $this->actingAs($admin);

        Livewire::test(Marketplace::class)
            ->call('openStageArtifactModal', self::CODE)
            ->assertSet('stagingModalOpen', true)
            ->assertSee('Staging не встановлює addon')
            ->call('stageArtifact')
            ->assertSet('stagingModalOpen', false);
        $metadata = $this->metadata();
        $this->assertSame('staged', $metadata['staging_status']);

        $before = Storage::disk('addons')->get($this->metadataPath);
        $this->actingAs($managerUser);
        Livewire::test(Marketplace::class)
            ->set('stagingArtifactCode', self::CODE)
            ->call('unstageArtifact');
        $this->assertSame($before, Storage::disk('addons')->get($this->metadataPath));

        $this->actingAs($admin);
        Livewire::test(Marketplace::class)
            ->call('openUnstageArtifactModal', self::CODE)
            ->assertSet('stagingModalOpen', true)
            ->assertSee('Quarantine ZIP')
            ->call('unstageArtifact')
            ->assertSet('stagingModalOpen', false);
        $this->assertSame('approved', $this->metadata()['review_status']);
    }

    private function prepareArtifact(bool $trusted = true): ArtifactReviewManager
    {
        $registryUrl = $this->registryUrl.'?request='.++$this->registryRequest;
        $bytes = $this->artifactBytes();
        $signature = base64_encode(sodium_crypto_sign_detached($bytes, $this->signingSecret));

        $artifact = [
            'url' => $this->artifactUrl,
            'type' => 'zip',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'signature' => [
                'type' => 'ed25519',
                'value' => $signature,
                'key_id' => $trusted ? 'review-key' : 'unknown-key',
                'payload_version' => 'raw-zip-v1',
            ],
        ];
        $registry = [
            'registry' => ['name' => 'review-test', 'version' => 'test-build', 'application_version' => '1.0.0', 'build_version' => 'test-build', 'schema_version' => '1', 'generated_at' => '2026-07-14T00:00:00+00:00'],
            'items' => [[
                'code' => self::CODE,
                'type' => 'module',
                'vendor' => 'Core',
                'name' => 'Analytics',
                'description' => 'Review test',
                'version' => self::VERSION,
                'category' => null, 'tags' => [], 'requires_platform' => null, 'dependencies' => [], 'is_featured' => false,
                'homepage_url' => null, 'documentation_url' => null,
                'publisher' => ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Test'],
                'published_at' => '2026-07-14T00:00:00+00:00',
                'artifact' => $artifact,
            ]],
        ];

        Http::fake([$registryUrl => Http::response($registry)]);
        config([
            'addons-registry.enabled' => true,
            'addons-registry.url' => $registryUrl,
            'addons-registry.allow_localhost' => true,
            'addons-registry.mode' => 'read_only',
            'addons-registry.trust.require_signature' => true,
            'addons-registry.trust.keys' => [[
                'publisher_id' => '11111111-1111-4111-8111-111111111111',
                'key_id' => 'review-key',
                'algorithm' => 'ed25519',
                'public_key' => base64_encode($this->signingPublic),
                'status' => 'active',
            ]],
            'addons-registry.trust.trusted_keys' => [],
            'addons-registry.downloads.disk' => 'addons',
            'addons-registry.downloads.quarantine_path' => 'addons/quarantine',
            'addons-registry.review.enabled' => true,
            'addons-registry.review.require_trusted' => true,
            'addons-registry.review.require_note_on_reject' => true,
            'addons-registry.review.allow_revoke' => true,
        ]);

        $directory = 'addons/quarantine/'.self::CODE.'/'.self::VERSION;
        Storage::disk('addons')->put($directory.'/core.analytics-1.0.0.zip', $bytes);
        $this->writeMetadata([
            'status' => 'quarantined',
            'verification_state' => 'verified',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
        ]);

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

        return app(ArtifactReviewManager::class);
    }

    private function artifactBytes(): string
    {
        return (string) file_get_contents(base_path('docs/examples/artifacts/core.analytics-1.0.0.zip'));
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return json_decode(Storage::disk('addons')->get($this->metadataPath), true);
    }

    /** @param array<string, mixed> $metadata */
    private function writeMetadata(array $metadata): void
    {
        Storage::disk('addons')->put($this->metadataPath, json_encode($metadata));
    }
}
