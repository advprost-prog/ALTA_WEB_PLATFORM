<?php

namespace Tests\PostgreSQL;

use App\Support\DatabaseTransition\SqliteToPostgreSqlPolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use SQLite3;
use Tests\TestCase;

#[Group('real-pg-transition')]
final class OneOffSqliteToPostgreSqlRealTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PG_TRANSITION_REAL_TEST') !== '1') {
            $this->markTestSkipped('Explicit real PostgreSQL transition gate is required.');
        }
    }

    public function test_imported_target_matches_protected_snapshot_read_only(): void
    {
        $snapshot = getenv('PG_TRANSITION_SNAPSHOT');
        $this->assertIsString($snapshot);
        $source = new SQLite3($snapshot, SQLITE3_OPEN_READONLY);
        $target = DB::connection('pgsql_transition');
        $identity = $target->selectOne("SELECT current_database() database, current_setting('server_version_num')::int version");
        $this->assertStringStartsWith('alta_pg_transition_', $identity->database);
        $this->assertGreaterThanOrEqual(180004, (int) $identity->version);
        foreach (SqliteToPostgreSqlPolicy::importedTables() as $table) {
            $this->assertSame((int) $source->querySingle('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"'), $target->table($table)->count(), $table);
        }
        foreach (SqliteToPostgreSqlPolicy::EXCLUDED as $table) {
            $this->assertSame(0, $target->table($table)->count(), $table);
        }
        $this->assertSame(0, (int) $target->selectOne('SELECT COUNT(*) count FROM categories child LEFT JOIN categories parent ON parent.id=child.parent_id WHERE child.parent_id IS NOT NULL AND parent.id IS NULL')->count);
        $this->assertSame(26, $target->table('migrations')->count());
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_storefront_read_smoke_uses_imported_target_without_mutation(): void
    {
        config(['database.default' => 'pgsql_transition']);
        $target = DB::connection('pgsql_transition');
        $before = [];
        foreach (SqliteToPostgreSqlPolicy::TABLES as $table) {
            $before[$table] = $target->table($table)->count();
        }
        $this->get('/')->assertOk();
        $this->get('/catalog')->assertOk();
        $after = [];
        foreach (SqliteToPostgreSqlPolicy::TABLES as $table) {
            $after[$table] = $target->table($table)->count();
        }
        $this->assertSame($before, $after);
    }
}
