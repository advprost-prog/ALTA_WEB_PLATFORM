<?php

namespace Tests\Unit;

use App\Support\DatabaseTransition\OneOffSqliteToPostgreSqlImporter;
use App\Support\DatabaseTransition\SqliteToPostgreSqlPolicy;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class OneOffSqliteToPostgreSqlImporterTest extends TestCase
{
    public function test_table_policy_is_explicit_and_complete(): void
    {
        $this->assertCount(53, SqliteToPostgreSqlPolicy::TABLES);
        $this->assertCount(45, SqliteToPostgreSqlPolicy::importedTables());
        $this->assertCount(7, SqliteToPostgreSqlPolicy::EXCLUDED);
        $this->assertCount(9, SqliteToPostgreSqlPolicy::SEEDED);
        $this->assertStringContainsString("'default' => env('DB_CONNECTION', 'sqlite')", file_get_contents(config_path('database.php')));
    }

    public function test_boolean_decimal_json_and_text_conversion_is_strict(): void
    {
        $method = new ReflectionMethod(OneOffSqliteToPostgreSqlImporter::class, 'convert');
        $importer = new OneOffSqliteToPostgreSqlImporter;
        $this->assertFalse($method->invoke($importer, 0, 'boolean'));
        $this->assertTrue($method->invoke($importer, 1, 'boolean'));
        $this->assertSame('12.340', $method->invoke($importer, '12.340', 'numeric'));
        $this->assertSame('{"ключ":[1,2]}', $method->invoke($importer, '{"ключ":[1,2]}', 'json'));
        $this->assertSame('Україна', $method->invoke($importer, 'Україна', 'text'));
        foreach ([[2, 'boolean'], [1.5, 'numeric'], ['{', 'json'], ["bad\0text", 'text']] as [$value, $type]) {
            try {
                $method->invoke($importer, $value, $type);
                $this->fail("Invalid {$type} accepted.");
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_live_source_and_sha_mismatch_are_rejected_before_target_access(): void
    {
        $target = Mockery::mock(ConnectionInterface::class);
        $target->shouldNotReceive('getDriverName');
        $importer = new OneOffSqliteToPostgreSqlImporter;
        $this->expectException(RuntimeException::class);
        $importer->import(database_path('database.sqlite'), str_repeat('0', 64), $target);
    }

    public function test_sha_mismatch_is_rejected_before_target_access(): void
    {
        $directory = sys_get_temp_dir().'/alta-one-off-source-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $snapshot = $directory.'/source.sqlite';
        file_put_contents($snapshot, 'synthetic-not-a-database');
        chmod($snapshot, 0600);
        $target = Mockery::mock(ConnectionInterface::class);
        $target->shouldNotReceive('getDriverName');
        try {
            (new OneOffSqliteToPostgreSqlImporter)->import($snapshot, str_repeat('0', 64), $target);
            $this->fail('SHA mismatch accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            unlink($snapshot);
            rmdir($directory);
        }
    }
}
