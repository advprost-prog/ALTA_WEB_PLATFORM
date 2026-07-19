<?php

namespace Tests\PostgreSQL;

use Alta\BackupRestore\Services\RestoreFaultInjector;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BackupRestoreIntegratedDrE2ETest extends TestCase
{
    private string $root;

    private string $suffix;

    private string $restoreRole;

    private string $adminRole;

    private string $sourceDatabase;

    private string $liveDatabase;

    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ALTA_DR_E2E') !== '1') {
            $this->markTestSkipped('Set ALTA_DR_E2E=1 for the destructive disposable DR gate.');
        }

        $this->suffix = strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
        $this->restoreRole = 'abr_restore_'.$this->suffix;
        $this->adminRole = 'abr_admin_'.$this->suffix;
        $this->sourceDatabase = 'alta_dr_'.$this->suffix.'_source';
        $this->liveDatabase = 'alta_dr_'.$this->suffix.'_live';
        $this->root = sys_get_temp_dir().'/alta-dr-e2e-'.$this->suffix;
        File::ensureDirectoryExists($this->root.'/package');
        File::ensureDirectoryExists($this->root.'/private');
        File::ensureDirectoryExists($this->root.'/public');

        $this->installAddonPackage();
        $this->migrateAddon();
        $this->createRolesAndDatabases();
        $this->configureHarness();
    }

    protected function tearDown(): void
    {
        try {
            Artisan::call('up');
            DB::purge('dr_source');
            DB::purge('dr_live');
            DB::purge('dr_secondary');
            DB::purge('dr_restore_target');
            DB::purge('dr_admin');
            if (isset($this->restoreRole)) {
                $this->dropOwnedDatabasesAndRoles();
            }
            foreach (array_reverse($this->migrations) as $migration) {
                $migration->down();
            }
            if (app()->bound(AddonManager::class)) {
                $manager = app(AddonManager::class);
                $addon = app(AddonRegistry::class)->find('alta.backup-restore');
                if ($addon?->is_enabled) {
                    $manager->disable($addon->code);
                }
                if ($addon?->is_installed) {
                    $manager->uninstall($addon->code);
                }
                $manager->lifecycle->unregisterServiceProvider('alta.backup-restore');
            }
        } finally {
            if (isset($this->root)) {
                File::deleteDirectory($this->root);
            }
            File::deleteDirectory(base_path('modules/ExternalContract/BackupRestore'));
            parent::tearDown();
        }
    }

    public function test_real_addon_dr_workflows_cover_secondary_primary_and_combined_compensation(): void
    {
        $this->fixture($this->sourceDatabase, 'restored');

        $databaseArtifact = $this->backup(false);
        $this->verifyStaging($databaseArtifact->public_id);

        $secondary = 'alta_dr_'.$this->suffix.'_secondary';
        config()->set('database.connections.dr_secondary.database', $secondary);
        config()->set('alta-backup-restore.database.postgresql.restore_admin.allowed_databases', [$secondary]);
        config()->set('alta-backup-restore.restore.targets.secondary', $this->target('secondary', $secondary, 'dr_secondary'));
        $secondaryOperation = $this->restoreThroughCli($databaseArtifact->public_id, 'secondary');
        $this->assertSame('completed', $secondaryOperation->state);
        $this->assertSame($this->digest($this->sourceDatabase), $this->digest($secondary));
        $this->assertContains('final_eligible', $secondaryOperation->execution_evidence['journal']);
        $this->assertDatabaseHas('alta_backup_restore_audit_events', ['event_type' => 'database_restore_executed', 'outcome' => 'completed']);
        $this->dropDatabase($secondary);

        $this->fixture($this->liveDatabase, 'current');
        $primaryTarget = 'alta_restore_'.$this->suffix.'_primary';
        config()->set('database.connections.dr_restore_target.database', $primaryTarget);
        config()->set('alta-backup-restore.database.postgresql.restore_admin.allowed_databases', [$primaryTarget]);
        config()->set('alta-backup-restore.restore.targets.primary', $this->target('primary', $primaryTarget, 'dr_restore_target'));
        $primaryOperation = $this->restoreThroughCli($databaseArtifact->public_id, 'primary');
        $this->assertSame('completed', $primaryOperation->state, json_encode($primaryOperation->execution_evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('restored', $this->marker($this->liveDatabase));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertContains('db_committed', $primaryOperation->execution_evidence['journal']);
        $this->dropOwnedRollbackDatabases();

        $this->fixture($this->liveDatabase, 'current');
        $rollbackTarget = 'alta_restore_'.$this->suffix.'_rollback';
        config()->set('database.connections.dr_restore_target.database', $rollbackTarget);
        config()->set('alta-backup-restore.database.postgresql.restore_admin.allowed_databases', [$rollbackTarget]);
        config()->set('alta-backup-restore.restore.targets.primary_rollback', $this->target('primary', $rollbackTarget, 'dr_restore_target'));
        $this->inject('after_primary_database_switch');
        $rollbackOperation = $this->restoreThroughCli($databaseArtifact->public_id, 'primary_rollback');
        $this->assertSame('rolled_back', $rollbackOperation->state);
        $this->assertSame('current', $this->marker($this->liveDatabase));
        $this->assertContains('compensation_started', $rollbackOperation->execution_evidence['journal']);
        $this->assertContains('db_rollback_completed', $rollbackOperation->execution_evidence['journal']);
        $this->assertContains('compensation_completed', $rollbackOperation->execution_evidence['journal']);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->dropOwnedRollbackDatabases();
        app()->instance('Alta\\BackupRestore\\Services\\RestoreFaultInjector', new RestoreFaultInjector);

        $this->writeFiles('restored');
        $restoredFiles = $this->filesystemContentDigest();
        $combinedArtifact = $this->backup(true);
        $this->verifyStaging($combinedArtifact->public_id);
        $this->fixture($this->liveDatabase, 'current');
        $this->writeFiles('current');
        $combinedTarget = 'alta_restore_'.$this->suffix.'_combined';
        config()->set('database.connections.dr_restore_target.database', $combinedTarget);
        config()->set('alta-backup-restore.database.postgresql.restore_admin.allowed_databases', [$combinedTarget]);
        config()->set('alta-backup-restore.restore.targets.combined', $this->target('combined', $combinedTarget, 'dr_restore_target'));
        $combinedOperation = $this->restoreThroughCli($combinedArtifact->public_id, 'combined');
        $this->assertSame('completed', $combinedOperation->state, json_encode($combinedOperation->execution_evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('restored', $this->marker($this->liveDatabase));
        $this->assertSame($restoredFiles, $this->filesystemContentDigest());
        foreach (File::allFiles($this->root.'/public') as $file) {
            $this->assertSame(0600, fileperms($file->getPathname()) & 0777);
        }
        $this->assertContains('files_committed', $combinedOperation->execution_evidence['journal']);
        $this->dropOwnedRollbackDatabases();
        $this->removeFileRollbackDirectories();

        $this->fixture($this->liveDatabase, 'current');
        $this->writeFiles('current');
        $compensationTarget = 'alta_restore_'.$this->suffix.'_comp';
        config()->set('database.connections.dr_restore_target.database', $compensationTarget);
        config()->set('alta-backup-restore.database.postgresql.restore_admin.allowed_databases', [$compensationTarget]);
        config()->set('alta-backup-restore.restore.targets.combined_compensation', $this->target('combined', $compensationTarget, 'dr_restore_target'));
        $beforeDatabase = $this->digest($this->liveDatabase);
        $beforeFiles = $this->filesystemDigest();
        $this->inject('after_file_root_promoted');
        $compensation = $this->restoreThroughCli($combinedArtifact->public_id, 'combined_compensation');
        $this->assertSame('rolled_back', $compensation->state);
        $this->assertSame($beforeDatabase, $this->digest($this->liveDatabase));
        $this->assertSame($beforeFiles, $this->filesystemDigest());
        foreach (['db_committed', 'compensation_started', 'files_rollback_completed', 'db_rollback_completed', 'compensation_completed'] as $stage) {
            $this->assertContains($stage, $compensation->execution_evidence['journal']);
        }
        $this->assertFalse(in_array('compensation_failed', $compensation->execution_evidence['journal'], true));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame([], glob($this->root.'/private/**/pgpass') ?: []);
    }

    private function installAddonPackage(): void
    {
        $source = realpath((string) getenv('ALTA_BACKUP_RESTORE_ADDON_PATH'));
        $this->assertIsString($source);
        $target = base_path('modules/ExternalContract/BackupRestore');
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);
        foreach (['module.json', 'composer.json', 'README.md'] as $file) {
            File::copy($source.'/'.$file, $target.'/'.$file);
        }
        foreach (['src', 'config', 'database', 'resources'] as $directory) {
            File::copyDirectory($source.'/'.$directory, $target.'/'.$directory);
        }
        $manager = app(AddonManager::class);
        $manager->lifecycle->unregisterServiceProvider('alta.backup-restore');
        DB::table('system_addon_events')->where('addon_code', 'alta.backup-restore')->delete();
        DB::table('system_addon_settings')->where('addon_code', 'alta.backup-restore')->delete();
        DB::table('system_addons')->where('code', 'alta.backup-restore')->delete();
        $manager->discover();
        $addon = app(AddonRegistry::class)->find('alta.backup-restore');
        $this->assertNotNull($addon);
        $manager->install($addon->code);
        $manager->enable($addon->code);
        $this->assertTrue(app()->providerIsLoaded('Alta\\BackupRestore\\BackupRestoreServiceProvider'));
        $this->assertInstanceOf('Alta\\BackupRestore\\Services\\HostRestoreBridgeProxy', app('Alta\\BackupRestore\\Contracts\\HostRestoreBridge'));
    }

    private function migrateAddon(): void
    {
        $root = base_path('modules/ExternalContract/BackupRestore/database/migrations');
        foreach (File::files($root) as $file) {
            $migration = require $file->getPathname();
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    private function createRolesAndDatabases(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE ROLE "'.$this->restoreRole.'" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
        $pdo->exec('CREATE ROLE "'.$this->adminRole.'" LOGIN NOSUPERUSER CREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
        $pdo->exec('GRANT "'.$this->restoreRole.'" TO "'.$this->adminRole.'"');
        $pdo->exec('GRANT pg_signal_backend TO "'.$this->adminRole.'"');
        $pdo->exec('CREATE DATABASE "'.$this->sourceDatabase.'" OWNER "'.$this->restoreRole.'"');
        $pdo->exec('CREATE DATABASE "'.$this->liveDatabase.'" OWNER "'.$this->restoreRole.'"');
    }

    private function configureHarness(): void
    {
        $base = config('database.connections.pgsql');
        $restore = array_merge($base, ['username' => $this->restoreRole, 'password' => null]);
        $admin = array_merge($base, ['database' => $base['database'], 'username' => $this->adminRole, 'password' => null]);
        config()->set('database.connections.dr_source', array_merge($restore, ['database' => $this->sourceDatabase]));
        config()->set('database.connections.dr_live', array_merge($restore, ['database' => $this->liveDatabase]));
        config()->set('database.connections.dr_secondary', array_merge($restore, ['database' => 'placeholder_secondary']));
        config()->set('database.connections.dr_restore_target', array_merge($restore, ['database' => 'placeholder_restore']));
        config()->set('database.connections.dr_admin', $admin);
        config()->set('filesystems.disks.dr_private', ['driver' => 'local', 'root' => $this->root.'/private', 'throw' => true]);
        config()->set('filesystems.disks.dr_public', ['driver' => 'local', 'root' => $this->root.'/public', 'throw' => true]);
        config()->set('alta-backup-restore.execution', array_merge(config('alta-backup-restore.execution'), ['backup_enabled' => true, 'restore_enabled' => true, 'planning_enabled' => true, 'files_enabled' => true]));
        config()->set('alta-backup-restore.storage.disk', 'dr_private');
        config()->set('alta-backup-restore.operations.disk_safety_margin_bytes', 0);
        config()->set('alta-backup-restore.sources.allowed_roots.public_uploads', ['disk' => 'dr_public', 'path' => '', 'label' => 'DR files', 'enabled' => true, 'max_file_count' => 100, 'max_aggregate_size' => 10485760, 'exclusions' => [], 'symlinks' => 'deny', 'special_files' => 'deny']);
        config()->set('alta-backup-restore.database.postgresql.enabled', true);
        config()->set('alta-backup-restore.database.postgresql.trusted_binary_directories', [(string) getenv('ALTA_PG_CLIENT_DIR')]);
        config()->set('alta-backup-restore.database.postgresql.process_environment.LD_LIBRARY_PATH', getenv('ALTA_PG_LIBRARY_DIR'));
        config()->set('alta-backup-restore.database.allowed_connections', [
            'dr_source' => ['engine' => 'postgresql', 'role' => 'secondary', 'backup_enabled' => true, 'restore_enabled' => false, 'allowed_server_major_versions' => [18], 'expected_schemas' => ['public']],
            'dr_secondary' => ['engine' => 'postgresql', 'role' => 'secondary', 'backup_enabled' => false, 'restore_enabled' => true, 'allowed_server_major_versions' => [18], 'expected_schemas' => ['public']],
            'dr_restore_target' => ['engine' => 'postgresql', 'role' => 'primary', 'backup_enabled' => false, 'restore_enabled' => true, 'allowed_server_major_versions' => [18], 'expected_schemas' => ['public']],
            'dr_live' => ['engine' => 'postgresql', 'role' => 'primary', 'backup_enabled' => false, 'restore_enabled' => true, 'allowed_server_major_versions' => [18], 'expected_schemas' => ['public']],
        ]);
        config()->set('alta-backup-restore.database.postgresql.staging', array_merge(config('alta-backup-restore.database.postgresql.staging'), ['enabled' => true, 'admin_connection' => 'dr_admin', 'restore_connection' => 'dr_source', 'restore_role' => $this->restoreRole, 'database_prefix' => 'alta_stg_', 'template_database' => 'template0', 'production_databases' => [$base['database'], $this->sourceDatabase, $this->liveDatabase]]));
        config()->set('alta-backup-restore.database.postgresql.restore_admin', ['connection' => 'dr_admin', 'allowed_databases' => [], 'allowed_backend_roles' => [$this->restoreRole], 'require_createdb' => true]);
        config()->set('backup-restore-host.allowed_connections', ['dr_live']);
        config()->set('backup-restore-host.allowed_databases', [$this->liveDatabase]);
        config()->set('backup-restore-host.allowed_backend_roles', [$this->restoreRole]);
        config()->set('backup-restore-host.admin_connection', 'dr_admin');
        config()->set('backup-restore-host.maintenance_store', 'file');
    }

    private function backup(bool $files): object
    {
        $profileClass = 'Alta\\BackupRestore\\Models\\Profile';
        $profile = $profileClass::create(['name' => 'DR '.$this->suffix.($files ? ' combined' : ' database'), 'status' => 'active', 'include_database' => true, 'include_files' => $files, 'database_connection' => 'dr_source', 'source_roots' => $files ? ['public_uploads'] : [], 'destination' => 'dr_private']);
        $run = app('Alta\\BackupRestore\\Services\\BackupRunOrchestrator')->run($profile);
        $this->assertSame('completed', $run->status->value, (string) $run->failure_summary);
        $artifact = $run->artifacts->first();
        $this->assertNotNull($artifact);
        $this->assertSame('available', $artifact->status->value);

        return $artifact;
    }

    private function verifyStaging(string $artifact): void
    {
        $verification = app('Alta\\BackupRestore\\Services\\PostgreSqlStagingVerificationService')->start($artifact);
        $this->assertSame('verified', $verification->state);
        $this->assertSame('completed', $verification->cleanup_status);
        $this->assertSame('retired', $verification->journal_status);
    }

    private function restoreThroughCli(string $artifact, string $target): object
    {
        $this->assertSame(0, Artisan::call('alta:backup-restore:database-preflight', ['artifact' => $artifact, 'target' => $target, '--json' => true]));
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('awaiting_confirmation', $payload['state']);
        $this->assertNotEmpty($payload['confirmation']);
        $exit = Artisan::call('alta:backup-restore:database-execute', ['operation' => $payload['operation_id'], '--confirmation' => $payload['confirmation'], '--ack-downtime' => true, '--ack-web-writers-stopped' => true, '--ack-queue-workers-stopped' => true, '--ack-schedulers-stopped' => true, '--json' => true]);
        $operationClass = 'Alta\\BackupRestore\\Models\\DatabaseRestoreOperation';
        $operation = $operationClass::query()->where('public_id', $payload['operation_id'])->firstOrFail();
        $this->assertSame(0, $exit, json_encode(['output' => Artisan::output(), 'state' => $operation->state, 'failure' => $operation->failure_code, 'evidence' => $operation->execution_evidence], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $operation;
    }

    private function target(string $mode, string $database, string $restoreConnection): array
    {
        return ['mode' => $mode, 'engine' => 'postgresql', 'target_database' => $database, 'restore_connection' => $restoreConnection, 'host_connection' => 'dr_live', 'single_node' => true];
    }

    private function inject(string $point): void
    {
        $class = 'Alta\\BackupRestore\\Services\\RestoreFaultInjector';
        app()->instance($class, new class($point) extends RestoreFaultInjector
        {
            public function __construct(private string $point) {}

            public function hit(string $point, ?string $rootId = null): void
            {
                if ($point === $this->point) {
                    throw new \RuntimeException('Deterministic integrated DR fault.');
                }
            }
        });
    }

    private function fixture(string $database, string $marker): void
    {
        $this->resetDatabase($database);
        $pdo = $this->connect($database);
        $pdo->exec('CREATE TABLE migrations (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, migration text NOT NULL, batch integer NOT NULL)');
        $pdo->exec("INSERT INTO migrations(migration,batch) VALUES ('dr_fixture',1)");
        $pdo->exec('CREATE TABLE restore_marker (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text NOT NULL UNIQUE)');
        $pdo->exec('CREATE TABLE restore_child (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, marker_id bigint NOT NULL REFERENCES restore_marker(id))');
        $pdo->exec('CREATE INDEX restore_child_marker_idx ON restore_child(marker_id)');
        $statement = $pdo->prepare('INSERT INTO restore_marker(value) VALUES (?)');
        $statement->execute([$marker]);
        $pdo->exec('INSERT INTO restore_child(marker_id) SELECT id FROM restore_marker');
    }

    private function resetDatabase(string $database): void
    {
        DB::purge($database === $this->sourceDatabase ? 'dr_source' : 'dr_live');
        $this->dropDatabase($database);
        DB::connection()->getPdo()->exec('CREATE DATABASE "'.$database.'" OWNER "'.$this->restoreRole.'"');
    }

    private function dropDatabase(string $database): void
    {
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid()');
        $statement->execute([$database]);
        $pdo->exec('DROP DATABASE IF EXISTS "'.$database.'"');
    }

    private function connect(string $database): \PDO
    {
        $base = config('database.connections.pgsql');

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $base['host'], $base['port'], $database), $this->restoreRole, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function marker(string $database): string
    {
        return (string) $this->connect($database)->query('SELECT value FROM restore_marker ORDER BY id')->fetchColumn();
    }

    private function digest(string $database): string
    {
        $pdo = $this->connect($database);
        $data = [
            'marker' => $pdo->query('SELECT jsonb_agg(to_jsonb(t) ORDER BY id)::text FROM restore_marker t')->fetchColumn(),
            'child' => $pdo->query('SELECT jsonb_agg(to_jsonb(t) ORDER BY id)::text FROM restore_child t')->fetchColumn(),
            'migrations' => $pdo->query('SELECT jsonb_agg(to_jsonb(t) ORDER BY id)::text FROM migrations t')->fetchColumn(),
            'fk' => $pdo->query("SELECT count(*) FROM information_schema.table_constraints WHERE constraint_type='FOREIGN KEY'")->fetchColumn(),
            'index' => $pdo->query("SELECT count(*) FROM pg_indexes WHERE schemaname='public'")->fetchColumn(),
            'sequence' => $pdo->query("SELECT count(*) FROM pg_sequences WHERE schemaname='public'")->fetchColumn(),
        ];

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function writeFiles(string $marker): void
    {
        File::deleteDirectory($this->root.'/public');
        File::ensureDirectoryExists($this->root.'/public/nested');
        File::put($this->root.'/public/nested/marker.txt', $marker."\n");
        File::put($this->root.'/public/порожній.txt', '');
        chmod($this->root.'/public/nested/marker.txt', 0600);
    }

    private function filesystemDigest(): string
    {
        $manifest = [];
        foreach (File::allFiles($this->root.'/public') as $file) {
            $manifest[$file->getRelativePathname()] = [hash_file('sha256', $file->getPathname()), $file->getSize(), fileperms($file->getPathname()) & 0777];
        }
        ksort($manifest);

        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function filesystemContentDigest(): string
    {
        $manifest = [];
        foreach (File::allFiles($this->root.'/public') as $file) {
            $manifest[$file->getRelativePathname()] = [hash_file('sha256', $file->getPathname()), $file->getSize()];
        }
        ksort($manifest);

        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function dropOwnedRollbackDatabases(): void
    {
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('SELECT datname FROM pg_database d JOIN pg_roles r ON r.oid=d.datdba WHERE r.rolname=? AND datname<>ALL(?)');
        $statement->execute([$this->restoreRole, '{'.$this->sourceDatabase.','.$this->liveDatabase.'}']);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $database) {
            $this->dropDatabase($database);
        }
    }

    private function removeFileRollbackDirectories(): void
    {
        foreach (glob($this->root.'/.abr-*') ?: [] as $path) {
            File::deleteDirectory($path);
        }
    }

    private function dropOwnedDatabasesAndRoles(): void
    {
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('SELECT datname FROM pg_database d JOIN pg_roles r ON r.oid=d.datdba WHERE r.rolname=?');
        $statement->execute([$this->restoreRole]);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $database) {
            $this->dropDatabase($database);
        }
        $pdo->exec('DROP ROLE IF EXISTS "'.$this->adminRole.'"');
        $pdo->exec('DROP ROLE IF EXISTS "'.$this->restoreRole.'"');
    }
}
