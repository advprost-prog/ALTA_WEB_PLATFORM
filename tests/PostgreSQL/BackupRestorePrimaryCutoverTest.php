<?php

namespace Tests\PostgreSQL;

use App\Support\Addons\BackupRestore\LaravelHostRestoreBridge;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BackupRestorePrimaryCutoverTest extends TestCase
{
    public function test_real_isolated_primary_cutover_health_and_rollback_preserve_both_digests(): void
    {
        $root = DB::connection()->getPdo();
        $role = 'alta_phase3_cutover_admin';
        $live = 'alta_phase3_cutover_live';
        $staged = 'alta_restore_phase3_cutover';

        $this->cleanup($root, [$live, $staged, 'alta_rollback_phase3cutover', 'alta_failed_'.substr(hash('sha256', $live.'|alta_rollback_phase3cutover'), 0, 24)]);
        $root->exec('DROP ROLE IF EXISTS "'.$role.'"');
        $root->exec('CREATE ROLE "'.$role.'" LOGIN NOSUPERUSER CREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
        $root->exec('GRANT pg_signal_backend TO "'.$role.'"');
        $root->exec('CREATE DATABASE "'.$live.'" OWNER "'.$role.'"');
        $root->exec('CREATE DATABASE "'.$staged.'" OWNER "'.$role.'"');

        $base = config('database.connections.pgsql');
        config()->set('database.connections.phase3_live', array_merge($base, ['database' => $live, 'username' => $role, 'password' => null]));
        config()->set('database.connections.phase3_admin', array_merge($base, ['database' => config('database.connections.pgsql.database'), 'username' => $role, 'password' => null]));
        config()->set('backup-restore-host.allowed_connections', ['phase3_live']);
        config()->set('backup-restore-host.allowed_databases', [$live]);
        config()->set('backup-restore-host.allowed_backend_roles', [$role]);
        config()->set('backup-restore-host.admin_connection', 'phase3_admin');

        try {
            DB::connection('phase3_live')->statement('CREATE TABLE restore_marker (value text NOT NULL)');
            DB::connection('phase3_live')->table('restore_marker')->insert(['value' => 'original']);
            $stagedPdo = new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $base['host'], $base['port'], $staged), $role, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $stagedPdo->exec("CREATE TABLE restore_marker (value text NOT NULL); INSERT INTO restore_marker VALUES ('restored')");
            $stagedPdo = null;

            $bridge = app(LaravelHostRestoreBridge::class);
            $request = ['target_connection' => 'phase3_live', 'single_node' => true, 'operation_id' => 'phase3-cutover'];
            $this->assertSame(0, $bridge->drain($request)['inventory_count']);
            $switch = $bridge->switchDatabase($request + ['staging' => ['target_database' => $staged]]);
            $this->assertSame('restored', DB::connection('phase3_live')->table('restore_marker')->value('value'));
            $this->assertTrue($bridge->health($request + ['zero_write' => true])['database']);

            $rollback = $bridge->rollback($request + ['evidence' => ['host_switch' => $switch]]);
            $this->assertTrue($rollback['rollback_reconciled']);
            $this->assertSame('original', DB::connection('phase3_live')->table('restore_marker')->value('value'));
        } finally {
            DB::purge('phase3_live');
            DB::purge('phase3_admin');
            $this->cleanup($root, [$live, $staged, 'alta_rollback_phase3cutover', 'alta_failed_'.substr(hash('sha256', $live.'|alta_rollback_phase3cutover'), 0, 24)]);
            $root->exec('DROP ROLE IF EXISTS "'.$role.'"');
        }
    }

    private function cleanup(\PDO $root, array $databases): void
    {
        foreach ($databases as $database) {
            $statement = $root->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid()');
            $statement->execute([$database]);
            $root->exec('DROP DATABASE IF EXISTS "'.$database.'"');
        }
    }
}
