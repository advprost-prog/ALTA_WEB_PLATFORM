<?php

namespace Tests\Unit;

use App\Providers\AddonServiceProvider;
use Tests\TestCase;

class BackupRestorePostgreSqlHostConfigTest extends TestCase
{
    public function test_explicit_postgresql_host_configuration_is_applied_without_path_discovery(): void
    {
        config([
            'database.connections.pgsql.driver' => 'pgsql',
            'backup-restore-host.postgresql_backup' => [
                'enabled' => true,
                'connections' => ['pgsql'],
                'client_directory' => '/configured/postgresql/bin',
                'library_path' => '/configured/postgresql/lib',
                'server_major_versions' => [18],
            ],
            'alta-backup-restore.database.postgresql' => ['enabled' => false],
            'alta-backup-restore.execution.backup_enabled' => false,
        ]);

        $this->configureProvider();

        $this->assertTrue(config('alta-backup-restore.execution.backup_enabled'));
        $this->assertSame(['/configured/postgresql/bin'], config('alta-backup-restore.database.postgresql.trusted_binary_directories'));
        $this->assertSame('/configured/postgresql/lib', config('alta-backup-restore.database.postgresql.process_environment.LD_LIBRARY_PATH'));
        $this->assertSame([18], config('alta-backup-restore.database.allowed_connections.pgsql.allowed_server_major_versions'));
        $this->assertFalse(config('alta-backup-restore.database.allowed_connections.pgsql.restore_enabled'));
    }

    public function test_incomplete_or_disabled_configuration_does_not_enable_backup(): void
    {
        config([
            'backup-restore-host.postgresql_backup' => [
                'enabled' => true,
                'connections' => ['pgsql'],
                'client_directory' => null,
                'library_path' => null,
                'server_major_versions' => [18],
            ],
            'alta-backup-restore.database.postgresql' => ['enabled' => false],
            'alta-backup-restore.execution.backup_enabled' => false,
        ]);

        $this->configureProvider();

        $this->assertFalse(config('alta-backup-restore.execution.backup_enabled'));
    }

    private function configureProvider(): void
    {
        $method = new \ReflectionMethod(AddonServiceProvider::class, 'configureBackupRestorePostgreSqlTools');
        $method->invoke(new AddonServiceProvider(app()));
    }
}
