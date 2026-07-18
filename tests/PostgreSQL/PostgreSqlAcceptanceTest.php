<?php

namespace Tests\PostgreSQL;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_schema_timezone_collation_and_extensions_match_pg_h2_contract(): void
    {
        $version = DB::selectOne('select current_setting(\'server_version\') as value')->value;
        $encoding = DB::selectOne('select current_setting(\'server_encoding\') as value')->value;
        $timezone = DB::selectOne("select current_setting('TimeZone') as value")->value;
        $extensions = DB::table('pg_extension')->orderBy('extname')->pluck('extname')->all();
        $ukrainianIcu = DB::table('pg_collation')
            ->where('collprovider', 'i')
            ->where('collname', 'like', 'uk%')
            ->pluck('collname');

        $this->assertTrue(version_compare($version, '18.4', '>='), "Unexpected PostgreSQL server: {$version}");
        $this->assertSame('UTF8', $encoding);
        $this->assertContains(strtoupper($timezone), ['UTC', 'ETC/UTC']);
        $this->assertSame(['plpgsql'], $extensions);
        $this->assertNotEmpty($ukrainianIcu, 'The official image should expose a Ukrainian ICU collation.');
    }

    public function test_all_host_foreign_keys_uniques_indexes_and_identity_columns_exist(): void
    {
        $foreignKeys = (int) DB::selectOne(<<<'SQL'
            select count(*) as aggregate
            from information_schema.table_constraints
            where table_schema = 'public' and constraint_type = 'FOREIGN KEY'
            SQL)->aggregate;
        $uniqueConstraints = (int) DB::selectOne(<<<'SQL'
            select count(*) as aggregate
            from information_schema.table_constraints
            where table_schema = 'public' and constraint_type in ('PRIMARY KEY', 'UNIQUE')
            SQL)->aggregate;
        $indexes = (int) DB::table('pg_indexes')->where('schemaname', 'public')->count();
        $sequenceBackedIds = (int) DB::selectOne(<<<'SQL'
            select count(*) as aggregate
            from information_schema.columns
            where table_schema = 'public'
              and column_name = 'id'
              and pg_get_serial_sequence(format('%I.%I', table_schema, table_name), column_name) is not null
            SQL)->aggregate;

        $this->assertSame(68, $foreignKeys);
        $this->assertGreaterThanOrEqual(31, $uniqueConstraints);
        $this->assertGreaterThanOrEqual(128, $indexes);
        $this->assertGreaterThan(20, $sequenceBackedIds);
    }

    public function test_postgresql_rejects_invalid_boolean_and_foreign_key_values(): void
    {
        try {
            DB::transaction(fn () => DB::table('products')->insert([
                'category_id' => 999999999,
                'name' => 'Invalid strict row',
                'slug' => 'invalid-strict-row',
                'sku' => 'INVALID-STRICT',
                'price' => 1,
                'stock' => 0,
                'stock_status' => 'in_stock',
                'status' => 'draft',
                'is_active' => 'not-a-boolean',
            ]));
            $this->fail('PostgreSQL accepted an invalid boolean/FK row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_explicit_id_does_not_advance_identity_and_sequence_is_catalog_resolvable(): void
    {
        DB::table('system_addon_events')->insert([
            'id' => 900000,
            'event' => 'pg_h2_explicit_identity',
            'level' => 'info',
            'message' => 'Disposable characterization row.',
        ]);
        $generatedId = DB::table('system_addon_events')->insertGetId([
            'event' => 'pg_h2_generated_identity',
            'level' => 'info',
            'message' => 'Disposable characterization row.',
        ]);
        $sequence = DB::selectOne("select pg_get_serial_sequence('public.system_addon_events', 'id') as name")->name;

        $this->assertNotNull($sequence);
        $this->assertLessThan(900000, $generatedId);
    }

    public function test_lock_for_update_blocks_a_second_connection_with_bounded_timeout(): void
    {
        $default = DB::connection();
        $config = Config::get('database.connections.pgsql');
        Config::set('database.connections.pgsql_lock_probe', $config);
        $probe = DB::connection('pgsql_lock_probe');

        $migrationId = DB::table('migrations')->min('id');
        $default->table('migrations')->where('id', $migrationId)->lockForUpdate()->first();

        try {
            $probe->statement("set lock_timeout = '500ms'");
            $probe->transaction(fn () => $probe->table('migrations')->where('id', $migrationId)->lockForUpdate()->first());
            $this->fail('The second connection unexpectedly acquired the locked row.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->errorInfo[0] ?? null);
        } finally {
            DB::disconnect('pgsql_lock_probe');
            Config::set('database.connections.pgsql_lock_probe');
        }
    }
}
