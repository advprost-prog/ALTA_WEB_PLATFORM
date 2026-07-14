<?php

namespace Tests\Feature;

use App\Models\SystemAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_unsupported_recovery_run_fails_closed_without_mutation(): void
    {
        Storage::fake('addons');
        $journal = ['schema_version' => 1, 'operation_id' => '22222222-2222-4222-8222-222222222222', 'code' => 'alta.recovery-fixture',
            'operation_type' => 'install', 'state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0'];
        $path = 'addons/install-journal/alta.recovery-fixture/'.$journal['operation_id'].'.json';
        Storage::disk('addons')->put($path, json_encode($journal));
        $before = Storage::disk('addons')->get($path);

        $this->artisan('addons:recovery:run '.$journal['operation_id'])
            ->expectsOutputToContain('automatic_recovery_not_implemented')
            ->assertExitCode(1);

        self::assertSame($before, Storage::disk('addons')->get($path));
    }
}
