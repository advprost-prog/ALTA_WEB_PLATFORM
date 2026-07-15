<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\AddonRecoveryHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddonRecoveryHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('addons');
        config([
            'addons-registry.recovery_health.enabled' => true,
            'addons-registry.recovery_health.cache_ttl' => 60,
            'addons-registry.recovery_health.stale_after' => 30,
            'addons-registry.recovery_health.max_operations' => 100,
        ]);
    }

    public function test_clean_health_is_cached_read_only_and_has_no_network(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network_not_allowed'));
        $before = Storage::disk('addons')->allFiles();
        $health = app(AddonRecoveryHealthService::class);

        $first = $health->health(true);
        $second = $health->health();

        $this->assertSame('healthy', $first['status']);
        $this->assertSame($first, $second);
        $this->assertSame($before, Storage::disk('addons')->allFiles());
        Http::assertNothingSent();
        $this->artisan('addons:recovery:health --json')->assertExitCode(0)->expectsOutputToContain('"status": "healthy"');
    }

    public function test_stale_unresolved_is_degraded_and_refresh_bypasses_cache(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $path = 'addons/install-journal/alta.health/'.$id.'.json';
        $journal = ['schema_version' => 1, 'operation_id' => $id, 'code' => 'alta.health', 'operation_type' => 'install',
            'state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0', 'started_at' => now()->subMinutes(5)->toIso8601String()];
        Storage::disk('addons')->put($path, json_encode($journal));
        $health = app(AddonRecoveryHealthService::class);

        $degraded = $health->health(true);
        $this->assertSame('degraded', $degraded['status']);
        $this->assertSame(1, $degraded['automatic_safe_count']);
        $this->assertSame('prepared_no_mutation', $degraded['items'][0]['classification']);
        $this->assertStringNotContainsString(Storage::disk('addons')->path(''), json_encode($degraded));

        $journal['state'] = 'completed';
        Storage::disk('addons')->put($path, json_encode($journal));
        $this->assertSame('degraded', $health->health()['status']);
        $this->assertSame('healthy', $health->health(true)['status']);
    }

    public function test_recent_unresolved_is_active_not_manual(): void
    {
        $id = '22222222-2222-4222-8222-222222222222';
        Storage::disk('addons')->put('addons/install-journal/alta.active/'.$id.'.json', json_encode([
            'schema_version' => 1, 'operation_id' => $id, 'code' => 'alta.active', 'operation_type' => 'update',
            'state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'started_at' => now()->toIso8601String(),
        ]));

        $health = app(AddonRecoveryHealthService::class)->health(true);
        $this->assertSame(1, $health['active_operation_count']);
        $this->assertSame(0, $health['manual_intervention_count']);
        $this->assertSame('active', $health['items'][0]['state']);
    }
}
