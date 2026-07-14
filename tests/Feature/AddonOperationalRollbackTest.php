<?php

namespace Tests\Feature;

use App\Models\SystemAddon;
use App\Support\Addons\Registry\AddonOperationalRollbackService;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\BackupIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddonOperationalRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('addons');
        config()->set('addons-registry.live_roots.modules_path', Storage::disk('addons')->path('modules'));
        config()->set('addons-registry.promotion.backup_disk', 'addons');
        config()->set('addons-registry.promotion.backup_path', 'addons/backups');
        config()->set('addons-registry.downloads.disk', 'addons');
        config()->set('addons-registry.downloads.quarantine_path', 'addons/quarantine');
    }

    public function test_completed_update_rolls_back_with_safety_backup_and_immutable_source(): void
    {
        $fixture = $this->fixture();
        $service = app(AddonOperationalRollbackService::class);
        $plan = $service->assess('alta.rollback', $fixture['operation_id']);
        self::assertTrue($plan['success']);

        $result = $service->rollback('alta.rollback', $fixture['operation_id'], $plan['fingerprint'], ArtifactReviewActor::cli());

        self::assertTrue($result['success'], json_encode($result));
        self::assertSame('1.0.0', SystemAddon::query()->where('code', 'alta.rollback')->value('version'));
        self::assertSame('1.0.0', json_decode(File::get($fixture['live'].'/module.json'), true)['version']);
        self::assertSame($fixture['source'], Storage::disk('addons')->get($fixture['source_path']));
        self::assertCount(1, Storage::disk('addons')->allFiles('addons/rollback-journal/alta.rollback'));
        $safety = collect(Storage::disk('addons')->directories('addons/backups/alta.rollback'))->first(fn (string $path): bool => str_contains($path, 'rollback-'));
        self::assertNotNull($safety);
        self::assertTrue(app(BackupIntegrityService::class)->verify(Storage::disk('addons')->path($safety))['valid']);
    }

    public function test_incompatible_installed_dependent_blocks_before_mutation_and_cli_dry_run_is_read_only(): void
    {
        $fixture = $this->fixture();
        SystemAddon::query()->create([
            'code' => 'alta.dependent', 'type' => 'module', 'name' => 'Dependent', 'vendor' => 'Alta', 'version' => '1.0.0',
            'source' => 'local', 'status' => 'installed', 'is_installed' => true, 'is_enabled' => false, 'manifest_path' => 'none',
            'metadata' => ['manifest' => ['dependencies' => [['code' => 'alta.rollback', 'constraint' => '>=2.0.0', 'required' => true]]]],
        ]);
        $beforeLive = hash_file('sha256', $fixture['live'].'/module.json');
        $beforeSource = Storage::disk('addons')->get($fixture['source_path']);

        $this->artisan('addons:rollback-version alta.rollback --operation='.$fixture['operation_id'].' --dry-run')
            ->expectsOutputToContain('rollback_dependency_blocked')->assertExitCode(1);

        self::assertSame($beforeLive, hash_file('sha256', $fixture['live'].'/module.json'));
        self::assertSame($beforeSource, Storage::disk('addons')->get($fixture['source_path']));
        self::assertSame('1.0.0', SystemAddon::query()->where('code', 'alta.dependent')->value('version'));
        self::assertSame([], Storage::disk('addons')->allFiles('addons/rollback-journal'));
    }

    public function test_discovery_failure_compensates_to_current_and_preserves_both_versions(): void
    {
        $fixture = $this->fixture(validPreviousManifest: false);
        $service = app(AddonOperationalRollbackService::class);
        $plan = $service->assess('alta.rollback', $fixture['operation_id']);
        self::assertTrue($plan['success']);

        $result = $service->rollback('alta.rollback', $fixture['operation_id'], $plan['fingerprint'], ArtifactReviewActor::cli());

        self::assertSame('rollback_discovery_failed', $result['code']);
        self::assertSame('2.0.0', SystemAddon::query()->where('code', 'alta.rollback')->value('version'));
        self::assertSame('2.0.0', json_decode(File::get($fixture['live'].'/module.json'), true)['version']);
        $rollbackJournal = json_decode(Storage::disk('addons')->get(Storage::disk('addons')->allFiles('addons/rollback-journal')[0]), true);
        self::assertSame('compensated_to_current', $rollbackJournal['state']);
        self::assertNotEmpty(glob(dirname($fixture['live']).'/.Rollback.rollback-failed-*'));
    }

    private function fixture(bool $validPreviousManifest = true): array
    {
        $code = 'alta.rollback';
        $operation = '55555555-5555-4555-8555-555555555555';
        $transaction = '66666666-6666-4666-8666-666666666666';
        $live = Storage::disk('addons')->path('modules/Alta/Rollback');
        File::makeDirectory($live, 0755, true);
        File::put($live.'/module.json', json_encode($this->manifest('2.0.0')));
        File::put($live.'/target.txt', 'target');
        SystemAddon::query()->create([
            'code' => $code, 'type' => 'module', 'name' => 'Rollback', 'vendor' => 'Alta', 'version' => '2.0.0',
            'source' => 'local', 'status' => 'installed', 'is_installed' => true, 'is_enabled' => false,
            'manifest_path' => 'storage/framework/testing/disks/addons/modules/Alta/Rollback/module.json', 'metadata' => ['manifest' => $this->manifest('2.0.0')],
        ]);
        $backup = Storage::disk('addons')->path('addons/backups/'.$code.'/previous-'.$transaction);
        File::makeDirectory($backup.'/payload', 0755, true);
        File::put($backup.'/payload/module.json', json_encode($validPreviousManifest ? $this->manifest('1.0.0') : ['code' => $code, 'version' => '1.0.0', 'type' => 'module', 'vendor' => 'Alta']));
        File::put($backup.'/payload/previous.txt', 'previous');
        app(BackupIntegrityService::class)->create($backup, [
            'code' => $code, 'version' => '1.0.0', 'type' => 'module', 'vendor' => 'Alta', 'operation_id' => $transaction,
            'operation_type' => 'update', 'previous_enabled' => false, 'installed_snapshot' => null,
        ]);
        Storage::disk('addons')->put('addons/quarantine/'.$code.'/2.0.0/metadata.json', json_encode([
            'promotion_live_path' => $live, 'promotion_backup_path' => $backup, 'promotion_transaction_id' => $transaction,
        ]));
        Storage::disk('addons')->put('addons/promotion-journal/'.$code.'/'.$transaction.'.json', json_encode([
            'transaction_id' => $transaction, 'addon_type' => 'module', 'live_path' => $live, 'backup_path' => null, 'staging_path' => null,
        ]));
        $journal = ['schema_version' => 1, 'operation_id' => $operation, 'code' => $code, 'operation_type' => 'update', 'state' => 'completed',
            'previous_version' => '1.0.0', 'previous_enabled' => false, 'target_version' => '2.0.0', 'promotion_transaction_id' => $transaction];
        $sourcePath = 'addons/install-journal/'.$code.'/'.$operation.'.json';
        Storage::disk('addons')->put($sourcePath, json_encode($journal));

        return ['operation_id' => $operation, 'live' => $live, 'source_path' => $sourcePath, 'source' => Storage::disk('addons')->get($sourcePath)];
    }

    private function manifest(string $version): array
    {
        return [
            'code' => 'alta.rollback', 'type' => 'module', 'name' => 'Rollback', 'version' => $version, 'vendor' => 'Alta',
            'description' => null, 'enabled_by_default' => false, 'service_provider' => null, 'dependencies' => [],
            'settings_schema' => [], 'compatibility' => ['app_min_version' => null, 'app_max_version' => null, 'php_version' => '>=8.3'],
            'permissions' => [], 'menu' => [], 'migrations' => [], 'seeders' => [], 'routes' => [],
        ];
    }
}
