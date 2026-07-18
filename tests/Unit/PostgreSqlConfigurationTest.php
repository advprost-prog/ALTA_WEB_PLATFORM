<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PostgreSqlConfigurationTest extends TestCase
{
    public function test_compose_profile_is_disposable_secretless_and_local_only(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/compose.postgresql.yml');

        $this->assertStringContainsString('postgres:18.4', $compose);
        $this->assertStringContainsString('127.0.0.1:${PG_H2_HOST_PORT:-55432}:5432', $compose);
        $this->assertStringContainsString('PG_H2_PASSWORD:?', $compose);
        $this->assertStringNotContainsString('database/database.sqlite', $compose);
    }

    public function test_postgresql_phpunit_profile_cannot_select_sqlite(): void
    {
        $profile = file_get_contents(dirname(__DIR__, 2).'/phpunit.postgresql.xml');

        $this->assertStringContainsString('name="DB_CONNECTION" value="pgsql" force="true"', $profile);
        $this->assertStringNotContainsString('DB_DATABASE" value=":memory:', $profile);
        $this->assertStringContainsString('<directory>tests/PostgreSQL</directory>', $profile);
    }

    public function test_ci_postgresql_job_is_mandatory_and_uses_masked_runtime_credential(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml');

        $this->assertStringContainsString("  postgresql:\n", $workflow);
        $this->assertStringContainsString('postgres:18.4', $workflow);
        $this->assertStringContainsString('::add-mask::', $workflow);
        $this->assertStringContainsString('export POSTGRES_PASSWORD="$PG_H2_PASSWORD"', $workflow);
        $this->assertStringContainsString('pdo_pgsql', $workflow);
        $this->assertStringNotContainsString('continue-on-error', $workflow);
        $this->assertStringNotContainsString('PGPASSWORD=', $workflow);
    }
}
