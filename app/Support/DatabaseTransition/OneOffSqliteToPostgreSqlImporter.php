<?php

namespace App\Support\DatabaseTransition;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use SQLite3;

final class OneOffSqliteToPostgreSqlImporter
{
    private SQLite3 $source;

    public function import(string $snapshot, string $sha256, ConnectionInterface $target): array
    {
        $this->assertSource($snapshot, $sha256);
        $this->assertTarget($target);
        $this->source = new SQLite3($snapshot, SQLITE3_OPEN_READONLY);
        $this->source->enableExceptions(true);
        $this->source->exec('PRAGMA query_only=ON');
        $this->assertInventories($target);
        $order = $this->topologicalOrder();
        $freshSeedCounts = [];

        $target->transaction(function () use ($target, &$freshSeedCounts): void {
            foreach (SqliteToPostgreSqlPolicy::SEEDED_CLEANUP_ORDER as $table) {
                $freshSeedCounts[$table] = $target->table($table)->count();
                $target->table($table)->delete();
            }
        });

        $counts = [];
        foreach ($order as $table) {
            $columns = $this->columns($table);
            $targetTypes = $this->targetTypes($target, $table);
            $rows = $this->rows($table, $columns, $targetTypes, $table === 'categories');
            $counts[$table] = count($rows);
            foreach (array_chunk($rows, 200) as $chunk) {
                $target->transaction(function () use ($target, $table, $chunk): void {
                    if ($chunk !== [] && ! $target->table($table)->insert($chunk)) {
                        throw new RuntimeException("Insert failed for {$table}.");
                    }
                });
            }
        }

        $restoredParents = 0;
        $result = $this->source->query('SELECT id, parent_id FROM categories WHERE parent_id IS NOT NULL ORDER BY id');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($target->table('categories')->where('id', $row['id'])->update(['parent_id' => $row['parent_id']]) !== 1) {
                throw new RuntimeException('Category parent second pass failed.');
            }
            $restoredParents++;
        }

        $sequences = $this->reconcileSequences($target, $order);

        return [
            'import_order' => $order,
            'counts' => $counts,
            'seeded_replacements' => $freshSeedCounts,
            'category_parents_restored' => $restoredParents,
            'sequences' => $sequences,
        ];
    }

    private function assertSource(string $snapshot, string $sha256): void
    {
        if (! str_starts_with($snapshot, DIRECTORY_SEPARATOR) || ! is_file($snapshot) || is_link($snapshot)) {
            throw new RuntimeException('A regular absolute SQLite snapshot is required.');
        }
        if (realpath($snapshot) === realpath(database_path('database.sqlite'))) {
            throw new RuntimeException('The live SQLite database cannot be imported directly.');
        }
        if (! preg_match('/\A[0-9a-f]{64}\z/', $sha256) || ! hash_equals($sha256, hash_file('sha256', $snapshot))) {
            throw new RuntimeException('Snapshot SHA-256 mismatch.');
        }
        if (((fileperms($snapshot) & 0777) & 0077) !== 0) {
            throw new RuntimeException('Snapshot permissions must be 0600 or stricter.');
        }
        $check = new SQLite3($snapshot, SQLITE3_OPEN_READONLY);
        $check->exec('PRAGMA query_only=ON');
        if ($check->querySingle('PRAGMA quick_check') !== 'ok' || $check->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Snapshot integrity check failed.');
        }
        if ($check->query('PRAGMA foreign_key_check')->fetchArray() !== false) {
            throw new RuntimeException('Snapshot foreign-key check failed.');
        }
    }

    private function assertTarget(ConnectionInterface $target): void
    {
        if ($target->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Target driver must be pgsql.');
        }
        $identity = $target->selectOne("SELECT current_database() database, current_setting('server_version_num')::int version, current_setting('server_encoding') encoding, current_setting('TimeZone') timezone");
        if (! str_starts_with($identity->database, 'alta_pg_transition_') || (int) $identity->version < 180004 || $identity->encoding !== 'UTF8' || strtoupper($identity->timezone) !== 'UTC') {
            throw new RuntimeException('Target identity, version, encoding or timezone is unsafe.');
        }
    }

    private function assertInventories(ConnectionInterface $target): void
    {
        $sourceTables = [];
        $result = $this->source->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sourceTables[] = $row['name'];
        }
        $expected = SqliteToPostgreSqlPolicy::TABLES;
        sort($expected);
        if ($sourceTables !== $expected) {
            throw new RuntimeException('Source table inventory differs from the explicit policy.');
        }
        $targetTables = array_map(fn ($row) => $row->tablename, $target->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"));
        if ($targetTables !== $expected || $target->table('migrations')->count() !== 26) {
            throw new RuntimeException('Target schema or migration inventory is invalid.');
        }
        foreach (SqliteToPostgreSqlPolicy::importedTables() as $table) {
            if (! in_array($table, SqliteToPostgreSqlPolicy::SEEDED, true) && $target->table($table)->count() !== 0) {
                throw new RuntimeException("Target table {$table} is not fresh.");
            }
        }
        foreach (SqliteToPostgreSqlPolicy::EXCLUDED as $table) {
            if ($target->table($table)->count() !== 0) {
                throw new RuntimeException("Excluded target table {$table} is not empty.");
            }
        }
    }

    private function topologicalOrder(): array
    {
        $tables = SqliteToPostgreSqlPolicy::importedTables();
        $dependencies = array_fill_keys($tables, []);
        foreach ($tables as $table) {
            $result = $this->source->query('PRAGMA foreign_key_list('.$this->quote($table).')');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['table'] !== $table && in_array($row['table'], $tables, true)) {
                    $dependencies[$table][] = $row['table'];
                }
            }
            $dependencies[$table] = array_values(array_unique($dependencies[$table]));
        }
        $order = [];
        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, fn ($items) => $items === []));
            sort($ready);
            if ($ready === []) {
                throw new RuntimeException('An unsupported foreign-key cycle was found.');
            }
            foreach ($ready as $table) {
                $order[] = $table;
                unset($dependencies[$table]);
            }
            foreach ($dependencies as &$items) {
                $items = array_values(array_diff($items, $ready));
            }
            unset($items);
        }

        return $order;
    }

    private function columns(string $table): array
    {
        $columns = [];
        $result = $this->source->query('PRAGMA table_info('.$this->quote($table).')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }

        return $columns;
    }

    private function targetTypes(ConnectionInterface $target, string $table): array
    {
        $rows = $target->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position", [$table]);

        return array_column(array_map(fn ($row) => ['name' => $row->column_name, 'type' => $row->data_type], $rows), 'type', 'name');
    }

    private function rows(string $table, array $columns, array $types, bool $clearParent): array
    {
        $select = [];
        foreach ($columns as $column) {
            $quoted = $this->quote($column);
            $select[] = $types[$column] === 'numeric' ? "CAST({$quoted} AS TEXT) AS {$quoted}" : $quoted;
        }
        $primary = $this->primaryKey($table);
        $sql = 'SELECT '.implode(', ', $select).' FROM '.$this->quote($table).' ORDER BY '.implode(', ', array_map($this->quote(...), $primary ?: $columns));
        $result = $this->source->query($sql);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            foreach ($row as $column => &$value) {
                $value = $this->convert($value, $types[$column]);
            }
            unset($value);
            if ($clearParent) {
                $row['parent_id'] = null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function convert(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'boolean') {
            if ($value !== 0 && $value !== 1) {
                throw new RuntimeException('Invalid SQLite boolean value.');
            }

            return $value === 1;
        }
        if ($type === 'numeric') {
            if (! is_string($value) || ! preg_match('/\A-?\d+(?:\.\d+)?\z/', $value)) {
                throw new RuntimeException('Invalid decimal value.');
            }

            return $value;
        }
        if ($type === 'json' || $type === 'jsonb') {
            json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

            return $value;
        }
        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
            throw new RuntimeException('Invalid UTF-8 or embedded NUL.');
        }

        return $value;
    }

    private function primaryKey(string $table): array
    {
        $primary = [];
        $result = $this->source->query('PRAGMA table_info('.$this->quote($table).')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ((int) $row['pk'] > 0) {
                $primary[(int) $row['pk']] = $row['name'];
            }
        }
        ksort($primary);

        return array_values($primary);
    }

    private function reconcileSequences(ConnectionInterface $target, array $tables): array
    {
        $results = [];
        foreach ($tables as $table) {
            if (! in_array('id', $this->columns($table), true)) {
                continue;
            }
            $sequence = $target->selectOne("SELECT pg_get_serial_sequence(format('%I.%I', 'public', ?::text), 'id') sequence", [$table])->sequence;
            if ($sequence === null) {
                continue;
            }
            $maximum = $target->table($table)->max('id');
            if ($maximum === null) {
                $target->select('SELECT setval(?::text::regclass, 1, false)', [$sequence]);
            } else {
                $target->select('SELECT setval(?::text::regclass, ?, true)', [$sequence, $maximum]);
            }
            $next = (int) $target->selectOne('SELECT nextval(?::text::regclass) value', [$sequence])->value;
            $target->select('SELECT setval(?::text::regclass, ?, ?)', [$sequence, $maximum ?? 1, $maximum !== null]);
            if (($maximum !== null && $next <= $maximum) || ($maximum === null && $next !== 1)) {
                throw new RuntimeException("Sequence verification failed for {$table}.");
            }
            $results[$table] = ['max_id' => $maximum, 'next_id' => $next];
        }

        return $results;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
