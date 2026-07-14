<?php

namespace Tests\Feature;

use App\Models\SystemAddon;
use App\Support\Addons\Registry\AddonRecoveryService;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddonRecoveryReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_scan_and_show_are_read_only_and_sanitized(): void
    {
        Storage::fake('addons');
        $journal = [
            'schema_version' => 1,
            'operation_id' => '11111111-1111-4111-8111-111111111111',
            'code' => 'alta.recovery-fixture',
            'operation_type' => 'install',
            'state' => 'prepared',
            'previous_version' => null,
            'target_version' => '2.0.0',
        ];
        $path = 'addons/install-journal/alta.recovery-fixture/'.$journal['operation_id'].'.json';
        Storage::disk('addons')->put($path, json_encode($journal));
        $beforeJournal = hash('sha256', Storage::disk('addons')->get($path));
        $beforeDatabase = SystemAddon::query()->count();
        $beforeFiles = Storage::disk('addons')->allFiles();

        $this->artisan('addons:recovery:scan --json')->assertExitCode(1);
        $this->artisan('addons:recovery:show '.$journal['operation_id'])
            ->expectsOutputToContain('prepared_no_mutation')
            ->doesntExpectOutputToContain(Storage::disk('addons')->path(''));

        self::assertSame($beforeJournal, hash('sha256', Storage::disk('addons')->get($path)));
        self::assertSame($beforeDatabase, SystemAddon::query()->count());
        self::assertSame($beforeFiles, Storage::disk('addons')->allFiles());
    }

    public function test_prepared_recovery_uses_separate_journal_and_is_idempotent(): void
    {
        Storage::fake('addons');
        $journal = ['schema_version' => 1, 'operation_id' => '22222222-2222-4222-8222-222222222222', 'code' => 'alta.recovery-fixture',
            'operation_type' => 'install', 'state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0'];
        $path = 'addons/install-journal/alta.recovery-fixture/'.$journal['operation_id'].'.json';
        Storage::disk('addons')->put($path, json_encode($journal));
        $before = Storage::disk('addons')->get($path);

        $this->artisan('addons:recovery:run '.$journal['operation_id'])
            ->expectsOutputToContain('recovery_completed')
            ->assertExitCode(0);

        self::assertSame($before, Storage::disk('addons')->get($path));
        self::assertCount(1, Storage::disk('addons')->allFiles('addons/recovery-journal'));

        $this->artisan('addons:recovery:run '.$journal['operation_id'])
            ->expectsOutputToContain('recovery_not_required')
            ->assertExitCode(1);
        self::assertSame($before, Storage::disk('addons')->get($path));
        self::assertCount(1, Storage::disk('addons')->allFiles('addons/recovery-journal'));
    }

    public function test_first_install_database_only_is_removed_without_touching_source_journal(): void
    {
        Storage::fake('addons');
        config()->set('addons-registry.live_roots.modules_path', Storage::disk('addons')->path('modules'));
        SystemAddon::query()->create([
            'code' => 'alta.partial', 'type' => 'module', 'name' => 'Partial', 'vendor' => 'Alta', 'version' => '2.0.0',
            'source' => 'marketplace', 'status' => 'installed', 'is_installed' => true, 'is_enabled' => false,
            'manifest_path' => 'modules/Alta/Partial/module.json', 'metadata' => ['manifest' => []],
        ]);
        $journal = ['schema_version' => 1, 'operation_id' => '33333333-3333-4333-8333-333333333333', 'code' => 'alta.partial',
            'operation_type' => 'install', 'state' => 'registering', 'previous_version' => null, 'target_version' => '2.0.0'];
        $path = 'addons/install-journal/alta.partial/'.$journal['operation_id'].'.json';
        Storage::disk('addons')->put($path, json_encode($journal));
        $before = Storage::disk('addons')->get($path);

        $this->artisan('addons:recovery:run '.$journal['operation_id'])->assertExitCode(0);

        self::assertFalse(SystemAddon::query()->where('code', 'alta.partial')->exists());
        self::assertSame($before, Storage::disk('addons')->get($path));
    }

    public function test_lock_and_fingerprint_changes_fail_before_mutation_and_dry_run_is_read_only(): void
    {
        Storage::fake('addons');
        $journal = ['schema_version' => 1, 'operation_id' => '44444444-4444-4444-8444-444444444444', 'code' => 'alta.locked',
            'operation_type' => 'install', 'state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0'];
        $path = 'addons/install-journal/alta.locked/'.$journal['operation_id'].'.json';
        Storage::disk('addons')->put($path, json_encode($journal));
        $before = Storage::disk('addons')->allFiles();

        $this->artisan('addons:recovery:run '.$journal['operation_id'].' --dry-run')
            ->expectsOutputToContain('recovery_plan_ready')->assertExitCode(0);
        self::assertSame($before, Storage::disk('addons')->allFiles());

        $service = app(AddonRecoveryService::class);
        self::assertSame('recovery_state_changed', $service->recover($journal['operation_id'], str_repeat('0', 64), ArtifactReviewActor::cli())['code']);
        $lock = Cache::lock('addon-install-operation:alta.locked', 60);
        self::assertTrue($lock->get());
        try {
            $assessment = $service->inspect($journal['operation_id']);
            self::assertSame('recovery_operation_active', $service->recover($journal['operation_id'], $assessment->fingerprint, ArtifactReviewActor::cli())['code']);
        } finally {
            $lock->release();
        }
        self::assertSame($before, Storage::disk('addons')->allFiles());
    }
}
