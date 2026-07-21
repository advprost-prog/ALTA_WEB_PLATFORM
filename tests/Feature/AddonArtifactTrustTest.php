<?php

namespace Tests\Feature;

use App\Console\Commands\Addons\InspectAddonArtifact;
use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use App\Support\Addons\Registry\ArtifactTrustEvaluator;
use App\Support\Addons\Registry\QuarantinedArtifactInspector;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonArtifactTrustTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private string $registryUrl = 'http://127.0.0.1:9001/registry.example.json';

    private string $artifactUrl = 'http://127.0.0.1:9001/artifacts/core.analytics-1.0.0.zip';

    private string $quarantineDir = 'addons/quarantine/core.analytics/1.0.0';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('addons');
        Storage::disk('addons')->deleteDirectory('addons/quarantine');
    }

    protected function tearDown(): void
    {
        Storage::disk('addons')->deleteDirectory('addons/quarantine');

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    /**
     * @return array{secret: string, public: string, public_b64: string}
     */
    private function makeKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);
        $secret = sodium_crypto_sign_secretkey($keypair);

        return [
            'secret' => $secret,
            'public' => $public,
            'public_b64' => base64_encode($public),
        ];
    }

    private function sign(string $bytes, string $secret): string
    {
        return base64_encode(sodium_crypto_sign_detached($bytes, $secret));
    }

    private function realArtifactBytes(): string
    {
        return (string) file_get_contents(base_path('docs/examples/artifacts/core.analytics-1.0.0.zip'));
    }

    private function realArtifactSha256(): string
    {
        return hash_file('sha256', base_path('docs/examples/artifacts/core.analytics-1.0.0.zip'));
    }

    /**
     * @param  array<string, mixed>  $artifactOverride
     */
    private function fakeRegistryWithArtifact(array $artifactOverride, string $body, ?string $sha256 = null): void
    {
        $sha256 ??= hash('sha256', $body);
        if (is_array($artifactOverride['signature'] ?? null)) {
            $artifactOverride['signature']['payload_version'] = 'raw-zip-v1';
        }

        $registry = [
            'registry' => ['name' => 'test', 'version' => 'test-build', 'application_version' => '1.0.0', 'build_version' => 'test-build', 'schema_version' => '1', 'generated_at' => '2026-07-14T00:00:00+00:00'],
            'items' => [
                [
                    'code' => 'core.analytics',
                    'type' => 'module',
                    'vendor' => 'Core',
                    'name' => 'Analytics',
                    'description' => 'Demo',
                    'version' => '1.0.0',
                    'category' => null, 'tags' => [], 'requires_platform' => null, 'dependencies' => [], 'is_featured' => false,
                    'homepage_url' => null, 'documentation_url' => null,
                    'publisher' => ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Test'],
                    'published_at' => '2026-07-14T00:00:00+00:00',
                    'artifact' => array_merge([
                        'url' => $this->artifactUrl,
                        'type' => 'zip',
                        'sha256' => $sha256,
                        'size' => strlen($body),
                        'signature' => ['type' => 'ed25519', 'value' => base64_encode('placeholder'), 'key_id' => 'placeholder', 'payload_version' => 'raw-zip-v1'],
                    ], $artifactOverride),
                ],
            ],
        ];

        Http::fake([
            $this->registryUrl => Http::response($registry, 200, ['Content-Type' => 'application/json']),
            $this->artifactUrl => Http::response($body, 200, ['Content-Type' => 'application/zip', 'Content-Length' => (string) strlen($body)]),
        ]);
    }

    /**
     * @param  array<string, string>  $trustedKeys
     */
    private function configureRegistry(bool $downloadsEnabled, bool $requireSignature, array $trustedKeys, array $overrides = []): void
    {
        $config = array_merge([
            'enabled' => true,
            'url' => $this->registryUrl,
            'timeout' => 5,
            'cache_ttl' => 60,
            'allowed_hosts' => [],
            'verify_ssl' => true,
            'allow_localhost' => true,
            'mode' => 'read_only',
            'trust' => [
                'require_signature' => $requireSignature,
                'trusted_keys' => $trustedKeys,
                'legacy_publishers' => array_fill_keys(array_keys($trustedKeys), '11111111-1111-4111-8111-111111111111'),
                'signature_verification_max_bytes' => 20 * 1024 * 1024,
            ],
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

    private function buildZip(string $manifestJson, string $basename = 'manifest.json'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'artifact').'.zip';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString($basename, $manifestJson);
        $zip->addFromString('README.md', '# demo');
        $zip->close();

        return $path;
    }

    /* ---------------------------------------------------------------------
     | ArtifactSignatureVerifier
     | ------------------------------------------------------------------ */

    public function test_signature_verifier_valid_ed25519(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $result = (new ArtifactSignatureVerifier)->verify($signature, $bytes, true, ['k1' => $keypair['public_b64']]);

        $this->assertSame(ArtifactSignatureVerifier::STATUS_VALID, $result->status);
        $this->assertSame('k1', $result->keyId);
    }

    public function test_signature_verifier_invalid_signature(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $bad = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));

        $result = (new ArtifactSignatureVerifier)->verify(
            ['type' => 'ed25519', 'value' => $bad, 'key_id' => 'k1'],
            $bytes,
            true,
            ['k1' => $keypair['public_b64']],
        );

        $this->assertSame(ArtifactSignatureVerifier::STATUS_INVALID, $result->status);
    }

    public function test_signature_verifier_missing_when_required(): void
    {
        $result = (new ArtifactSignatureVerifier)->verify(null, 'x', true, []);

        $this->assertSame(ArtifactSignatureVerifier::STATUS_MISSING, $result->status);
    }

    public function test_signature_verifier_not_required(): void
    {
        $result = (new ArtifactSignatureVerifier)->verify(null, 'x', false, []);

        $this->assertSame(ArtifactSignatureVerifier::STATUS_NOT_REQUIRED, $result->status);
    }

    public function test_signature_verifier_unknown_key(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();

        $result = (new ArtifactSignatureVerifier)->verify(
            ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'unknown'],
            $bytes,
            true,
            ['k1' => $keypair['public_b64']],
        );

        $this->assertSame(ArtifactSignatureVerifier::STATUS_UNKNOWN_KEY, $result->status);
    }

    public function test_signature_verifier_unsupported_type(): void
    {
        $result = (new ArtifactSignatureVerifier)->verify(
            ['type' => 'rsa', 'value' => 'x', 'key_id' => 'k1'],
            'x',
            true,
            ['k1' => 'x'],
        );

        $this->assertSame(ArtifactSignatureVerifier::STATUS_UNSUPPORTED_TYPE, $result->status);
    }

    public function test_signature_verifier_error_when_sodium_unavailable(): void
    {
        $verifier = new class extends ArtifactSignatureVerifier
        {
            public function sodiumAvailable(): bool
            {
                return false;
            }
        };

        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();

        $result = $verifier->verify(
            ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'],
            $bytes,
            true,
            ['k1' => $keypair['public_b64']],
        );

        $this->assertSame(ArtifactSignatureVerifier::STATUS_ERROR, $result->status);
        $this->assertStringContainsStringIgnoringCase('sodium', $result->diagnostics[0]);
    }

    /* ---------------------------------------------------------------------
     | QuarantinedArtifactInspector
     | ------------------------------------------------------------------ */

    public function test_inspector_valid_manifest(): void
    {
        $path = $this->buildZip(json_encode(['code' => 'core.analytics', 'version' => '1.0.0', 'type' => 'module']));

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_VALID, $result->status);
    }

    public function test_inspector_manifest_missing(): void
    {
        $path = $this->buildZip('not a manifest', 'README.md');

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_MANIFEST_MISSING, $result->status);
    }

    public function test_inspector_manifest_invalid_json(): void
    {
        $path = $this->buildZip('{not json', 'manifest.json');

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_MANIFEST_INVALID, $result->status);
    }

    public function test_inspector_code_mismatch(): void
    {
        $path = $this->buildZip(json_encode(['code' => 'other', 'version' => '1.0.0', 'type' => 'module']));

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_IDENTITY_MISMATCH, $result->status);
    }

    public function test_inspector_version_mismatch(): void
    {
        $path = $this->buildZip(json_encode(['code' => 'core.analytics', 'version' => '2.0.0', 'type' => 'module']));

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_IDENTITY_MISMATCH, $result->status);
    }

    public function test_inspector_broken_zip_errors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broken').'.zip';
        file_put_contents($path, 'not a zip');

        $result = (new QuarantinedArtifactInspector)->inspect($path, 'core.analytics', '1.0.0');

        $this->assertSame(QuarantinedArtifactInspector::STATUS_ERROR, $result->status);
    }

    /* ---------------------------------------------------------------------
     | ArtifactTrustEvaluator
     | ------------------------------------------------------------------ */

    public function test_evaluator_trusted(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            true,
            ArtifactSignatureVerifier::STATUS_VALID,
            QuarantinedArtifactInspector::STATUS_VALID,
            true,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_TRUSTED, $result->trustStatus);
    }

    public function test_evaluator_untrusted_when_signature_missing_required(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            true,
            ArtifactSignatureVerifier::STATUS_MISSING,
            QuarantinedArtifactInspector::STATUS_VALID,
            true,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_UNTRUSTED, $result->trustStatus);
    }

    public function test_evaluator_rejected_when_signature_invalid(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            true,
            ArtifactSignatureVerifier::STATUS_INVALID,
            QuarantinedArtifactInspector::STATUS_VALID,
            true,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_REJECTED, $result->trustStatus);
    }

    public function test_evaluator_rejected_when_manifest_mismatch(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            true,
            ArtifactSignatureVerifier::STATUS_VALID,
            QuarantinedArtifactInspector::STATUS_IDENTITY_MISMATCH,
            true,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_REJECTED, $result->trustStatus);
    }

    public function test_evaluator_partially_trusted_when_signature_not_required(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            true,
            ArtifactSignatureVerifier::STATUS_NOT_REQUIRED,
            QuarantinedArtifactInspector::STATUS_VALID,
            false,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_PARTIALLY_TRUSTED, $result->trustStatus);
    }

    public function test_evaluator_rejected_on_checksum_mismatch(): void
    {
        $result = (new ArtifactTrustEvaluator)->evaluate(
            false,
            ArtifactSignatureVerifier::STATUS_VALID,
            QuarantinedArtifactInspector::STATUS_VALID,
            true,
        );

        $this->assertSame(ArtifactTrustEvaluator::TRUST_REJECTED, $result->trustStatus);
    }

    /* ---------------------------------------------------------------------
     | MarketplaceManager::inspectArtifact
     | ------------------------------------------------------------------ */

    public function test_inspect_signed_trusted(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $manager->downloadArtifact('core.analytics');
        $report = $manager->inspectArtifact('core.analytics');

        $this->assertTrue($report['success']);
        $this->assertSame('trusted', $report['status']);
        $this->assertSame('valid', $report['report']['signature_status']);
        $this->assertSame('valid', $report['report']['manifest_status']);
    }

    public function test_inspect_uses_structured_publisher_key_binding(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'structured-key'];

        $this->configureRegistry(true, true, []);
        config(['addons-registry.trust.keys' => [[
            'publisher_id' => '11111111-1111-4111-8111-111111111111',
            'key_id' => 'structured-key',
            'algorithm' => 'ed25519',
            'public_key' => $keypair['public_b64'],
            'status' => 'active',
        ]]]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $this->assertTrue($manager->downloadArtifact('core.analytics')->success);
        $report = $manager->inspectArtifact('core.analytics');

        $this->assertTrue($report['success']);
        $this->assertSame('valid', $report['report']['signature_status']);
        $this->assertSame('valid', $report['report']['manifest_status']);
        $this->assertSame('trusted', $report['report']['trust_status']);
    }

    public function test_inspect_rejects_same_key_bound_to_different_publisher(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'structured-key'];

        $this->configureRegistry(true, true, []);
        config(['addons-registry.trust.keys' => [[
            'publisher_id' => '11111111-1111-4111-8111-111111111111',
            'key_id' => 'structured-key',
            'algorithm' => 'ed25519',
            'public_key' => $keypair['public_b64'],
            'status' => 'active',
        ]]]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $this->assertTrue($manager->downloadArtifact('core.analytics')->success);
        config(['addons-registry.trust.keys.0.publisher_id' => '22222222-2222-4222-8222-222222222222']);

        $report = $manager->inspectArtifact('core.analytics');

        $this->assertTrue($report['success']);
        $this->assertSame('unknown_key', $report['report']['signature_status']);
        $this->assertSame('valid', $report['report']['manifest_status']);
        $this->assertSame('untrusted', $report['report']['trust_status']);
    }

    public function test_unsigned_registry_item_fails_closed_before_download(): void
    {
        $this->configureRegistry(true, true, []);
        $this->fakeRegistryWithArtifact(['signature' => null], $this->realArtifactBytes());

        $manager = app(MarketplaceManager::class);
        $download = $manager->downloadArtifact('core.analytics');
        $this->assertFalse($download->success);
        $this->assertSame('remote_state_untrusted', $download->status);
    }

    public function test_inspect_invalid_signature_rejected(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $bad = ['type' => 'ed25519', 'value' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $bad], $bytes);

        $manager = app(MarketplaceManager::class);
        $download = $manager->downloadArtifact('core.analytics');
        $report = $manager->inspectArtifact('core.analytics');

        $this->assertFalse($download->success);
        $this->assertSame('signature_invalid', $download->status);
        $this->assertSame('not_downloaded', $report['status']);
    }

    public function test_inspect_unknown_key_untrusted(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'unknown'];

        $this->configureRegistry(true, true, ['k1' => 'x']);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $download = $manager->downloadArtifact('core.analytics');
        $report = $manager->inspectArtifact('core.analytics');

        $this->assertSame('unknown_signing_key', $download->status);
        $this->assertSame('not_downloaded', $report['status']);
    }

    public function test_inspect_manifest_mismatch_rejected(): void
    {
        $keypair = $this->makeKeypair();
        $manifestMismatchBytes = file_get_contents($this->buildZip(json_encode(['code' => 'other', 'version' => '1.0.0', 'type' => 'module'])));
        $signature = ['type' => 'ed25519', 'value' => $this->sign($manifestMismatchBytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $manifestMismatchBytes);

        $manager = app(MarketplaceManager::class);
        $download = $manager->downloadArtifact('core.analytics');
        $report = $manager->inspectArtifact('core.analytics');

        $this->assertSame('manifest_identity_mismatch', $download->status);
        $this->assertSame('not_downloaded', $report['status']);
    }

    public function test_disabling_signature_requirement_does_not_allow_unsigned_registry_fallback(): void
    {
        $this->configureRegistry(true, false, []);
        $this->fakeRegistryWithArtifact(['signature' => null], $this->realArtifactBytes());

        $manager = app(MarketplaceManager::class);
        $download = $manager->downloadArtifact('core.analytics');
        $this->assertFalse($download->success);
        $this->assertSame('remote_state_untrusted', $download->status);
    }

    public function test_inspect_not_downloaded_fails(): void
    {
        $this->configureRegistry(true, true, []);
        $this->fakeRegistryWithArtifact([], $this->realArtifactBytes());

        $report = app(MarketplaceManager::class)->inspectArtifact('core.analytics');

        $this->assertFalse($report['success']);
        $this->assertSame('not_downloaded', $report['status']);
    }

    public function test_inspect_persists_metadata(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $manager->downloadArtifact('core.analytics');
        $manager->inspectArtifact('core.analytics');

        $metadata = json_decode(Storage::disk('addons')->get($this->quarantineDir.'/metadata.json'), true);

        $this->assertSame('valid', $metadata['signature_status']);
        $this->assertSame('k1', $metadata['signature_key_id']);
        $this->assertSame('valid', $metadata['manifest_status']);
        $this->assertSame('trusted', $metadata['trust_status']);
        $this->assertSame('pending', $metadata['review_status']);
        $this->assertArrayHasKey('signature_checked_at', $metadata);
        $this->assertArrayHasKey('manifest_checked_at', $metadata);
    }

    /* ---------------------------------------------------------------------
     | CLI addons:inspect-artifact
     | ------------------------------------------------------------------ */

    public function test_cli_inspect_artifact_works(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->artisan(InspectAddonArtifact::class, ['code' => 'core.analytics'])
            ->assertSuccessful()
            ->expectsOutputToContain('trust:')
            ->expectsOutputToContain('signature:')
            ->expectsOutputToContain('manifest:');
    }

    /* ---------------------------------------------------------------------
     | Doctor diagnostics
     | ------------------------------------------------------------------ */

    public function test_doctor_does_not_accept_unsigned_registry_item(): void
    {
        $this->configureRegistry(true, true, []);
        $this->fakeRegistryWithArtifact(['signature' => null], $this->realArtifactBytes());

        $result = app(MarketplaceManager::class)->downloadArtifact('core.analytics');
        $this->assertFalse($result->success);
        $this->assertSame('remote_state_untrusted', $result->status);
    }

    public function test_doctor_invalid_signature_error(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $bad = ['type' => 'ed25519', 'value' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $bad], $bytes);

        $result = app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->assertSame('signature_invalid', $result->status);
        $this->assertFalse(Storage::disk('addons')->exists($this->quarantineDir.'/metadata.json'));
    }

    public function test_doctor_manifest_mismatch_error(): void
    {
        $keypair = $this->makeKeypair();
        $mismatchBytes = file_get_contents($this->buildZip(json_encode(['code' => 'other', 'version' => '1.0.0', 'type' => 'module'])));
        $signature = ['type' => 'ed25519', 'value' => $this->sign($mismatchBytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $mismatchBytes);

        $result = app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $this->assertSame('manifest_identity_mismatch', $result->status);
        $this->assertFalse(Storage::disk('addons')->exists($this->quarantineDir.'/metadata.json'));
    }

    /* ---------------------------------------------------------------------
     | UI / Livewire
     | ------------------------------------------------------------------ */

    public function test_marketplace_html_has_inspect_artifact_wire_click(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];
        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        app(MarketplaceManager::class)->downloadArtifact('core.analytics');

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $markup = file_get_contents(resource_path('views/filament/pages/marketplace.blade.php'));
        $this->assertStringContainsString('wire:click="inspectArtifact', $markup);
    }

    public function test_livewire_inspect_artifact_updates_metadata(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $manager->downloadArtifact('core.analytics');

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Marketplace::class)
            ->call('downloadArtifact', 'core.analytics')
            ->assertHasNoErrors()
            ->call('inspectArtifact', 'core.analytics')
            ->assertHasNoErrors();

        $status = $manager->getArtifactTrustStatus('core.analytics');
        $this->assertSame('trusted', $status);
    }

    public function test_marketplace_renders_trusted_and_rejected_badges(): void
    {
        $keypair = $this->makeKeypair();
        $bytes = $this->realArtifactBytes();
        $signature = ['type' => 'ed25519', 'value' => $this->sign($bytes, $keypair['secret']), 'key_id' => 'k1'];

        $this->configureRegistry(true, true, ['k1' => $keypair['public_b64']]);
        $this->fakeRegistryWithArtifact(['signature' => $signature], $bytes);

        $manager = app(MarketplaceManager::class);
        $manager->downloadArtifact('core.analytics');
        $manager->inspectArtifact('core.analytics');

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $this->assertSame('trusted', $manager->getArtifactTrustStatus('core.analytics'));
        $markup = file_get_contents(resource_path('views/filament/pages/marketplace.blade.php'));
        $this->assertStringContainsString('Довірений', $markup);
        $this->assertStringContainsString('Встановлення з quarantine буде доступне у наступній фазі.', $markup);
    }
}
