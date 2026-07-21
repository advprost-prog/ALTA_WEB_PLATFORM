<?php

namespace Tests\Feature;

use Alta\BackupRestore\Exceptions\BackupException;
use Alta\BackupRestore\Services\PostgreSqlToolchainInspector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupRestorePostgreSqlToolIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/postgresql-tools');
        $package = storage_path('app/addons/manual-live/modules/ALTA/BackupRestore');
        if (! class_exists(PostgreSqlToolchainInspector::class) && is_file($package.'/src/Services/PostgreSqlToolchainInspector.php')) {
            require_once $package.'/src/Exceptions/BackupException.php';
            require_once $package.'/src/Services/TrustedProcessRunner.php';
            require_once $package.'/src/Services/PostgreSqlToolchainInspector.php';
        }
        if (! class_exists(PostgreSqlToolchainInspector::class)) {
            $this->markTestSkipped('Installed Backup & Restore package is unavailable.');
        }
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_postgresql_18_tools_are_accepted_from_exact_canonical_directory(): void
    {
        $this->writeToolchain('18.4 (Ubuntu 18.4-1.pgdg24.04+1)');

        $tools = $this->inspect();

        $this->assertSame(18, $tools['pg_dump']['major']);
        $this->assertSame(realpath($this->root.'/pg_dump'), $tools['pg_dump']['path']);
        $this->assertStringNotContainsString($this->root, json_encode(['version' => $tools['pg_dump']['version']]));
    }

    public function test_malformed_and_non_postgresql_identity_fail_closed(): void
    {
        foreach (['not a version', 'pg_dump (OtherDB) 18.4'] as $output) {
            $this->writeToolchain('18.4', $output);
            $this->assertFailure('database_tool_identity_invalid');
        }
    }

    public function test_untrusted_symlink_escape_and_non_executable_tool_fail_closed(): void
    {
        $outside = $this->root.'-outside';
        File::ensureDirectoryExists($outside);
        $this->writeExecutable($outside.'/pg_dump', 'pg_dump (PostgreSQL) 18.4');
        symlink($outside.'/pg_dump', $this->root.'/pg_dump');
        foreach (['pg_restore', 'psql'] as $name) {
            $this->writeExecutable($this->root.'/'.$name, $name.' (PostgreSQL) 18.4');
        }
        $this->assertFailure('database_tool_untrusted');
        File::deleteDirectory($outside);

        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
        $this->writeToolchain('18.4');
        chmod($this->root.'/pg_dump', 0600);
        $this->assertFailure('database_tool_missing');
    }

    private function inspect(): array
    {
        config([
            'alta-backup-restore.database.postgresql.trusted_binary_directories' => [$this->root],
            'alta-backup-restore.database.postgresql.supported_versions' => [16 => ['minimum_minor' => 0], 17 => ['minimum_minor' => 0], 18 => ['minimum_minor' => 4]],
            'alta-backup-restore.database.postgresql.process_environment' => [],
        ]);

        return app(PostgreSqlToolchainInspector::class)->inspect();
    }

    private function assertFailure(string $code): void
    {
        try {
            $this->inspect();
            $this->fail('Tool identity inspection should fail closed.');
        } catch (BackupException $exception) {
            $this->assertSame($code, $exception->failureCode);
            $this->assertStringNotContainsString($this->root, $exception->getMessage());
        }
    }

    private function writeToolchain(string $version, ?string $pgDumpOutput = null): void
    {
        foreach (['pg_dump', 'pg_restore', 'psql'] as $name) {
            $this->writeExecutable($this->root.'/'.$name, $name === 'pg_dump' && $pgDumpOutput !== null ? $pgDumpOutput : $name.' (PostgreSQL) '.$version);
        }
    }

    private function writeExecutable(string $path, string $output): void
    {
        File::put($path, "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($output)."\n");
        chmod($path, 0700);
    }
}
