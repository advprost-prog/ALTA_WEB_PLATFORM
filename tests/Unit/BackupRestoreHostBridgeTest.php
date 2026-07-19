<?php

namespace Tests\Unit;

use App\Support\Addons\BackupRestore\HostRestoreException;
use App\Support\Addons\BackupRestore\LaravelHostRestoreBridge;
use Tests\TestCase;

final class BackupRestoreHostBridgeTest extends TestCase
{
    public function test_bridge_supports_allowlisted_sqlite_preflight_drain_and_reconnect(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'abr-host-sqlite-');
        $this->assertIsString($path);
        config()->set('database.connections.restore_sqlite', ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('backup-restore-host.allowed_connections', ['restore_sqlite']);
        config()->set('backup-restore-host.allowed_databases', [$path]);
        $bridge = app(LaravelHostRestoreBridge::class);

        $this->assertSame('sqlite', $bridge->preflight(['target_connection' => 'restore_sqlite', 'single_node' => true])['driver']);
        $this->assertSame(0, $bridge->drain(['target_connection' => 'restore_sqlite'])['terminated_count']);
        $this->assertTrue($bridge->reconnect(['target_connection' => 'restore_sqlite'])['reconnected']);
        @unlink($path);
    }

    public function test_bridge_rejects_unallowlisted_connection_before_database_access(): void
    {
        config()->set('backup-restore-host.allowed_connections', ['pgsql']);
        try {
            app(LaravelHostRestoreBridge::class)->preflight(['target_connection' => 'attacker', 'single_node' => true]);
            $this->fail('Expected fail-closed connection policy.');
        } catch (HostRestoreException $exception) {
            $this->assertSame('restore_host_connection_untrusted', $exception->failureCode);
        }
    }

    public function test_bridge_requires_zero_write_health_and_allowlisted_rollback(): void
    {
        $bridge = app(LaravelHostRestoreBridge::class);
        try {
            $bridge->health(['target_connection' => 'pgsql', 'zero_write' => false]);
            $this->fail('Expected zero-write health policy.');
        } catch (HostRestoreException $exception) {
            $this->assertSame('restore_host_health_mode_unsafe', $exception->failureCode);
        }
        try {
            $bridge->rollback(['previous_connection' => 'unknown']);
            $this->fail('Expected rollback allowlist policy.');
        } catch (HostRestoreException $exception) {
            $this->assertSame('restore_host_rollback_target_untrusted', $exception->failureCode);
        }
    }
}
