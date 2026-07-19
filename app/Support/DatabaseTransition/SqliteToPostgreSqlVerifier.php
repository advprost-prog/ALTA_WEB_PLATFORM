<?php

namespace App\Support\DatabaseTransition;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use SQLite3;

final class SqliteToPostgreSqlVerifier
{
    public function verify(string $snapshot, ConnectionInterface $target, array $importResult): array
    {
        $source = new SQLite3($snapshot, SQLITE3_OPEN_READONLY);
        $source->exec('PRAGMA query_only=ON');
        $tables = [];
        foreach (SqliteToPostgreSqlPolicy::importedTables() as $table) {
            $sourceCount = (int) $source->querySingle('SELECT COUNT(*) FROM '.$this->quote($table));
            $targetCount = $target->table($table)->count();
            if ($sourceCount !== $targetCount) {
                throw new RuntimeException("Row-count mismatch for {$table}.");
            }
            $primary = $this->primaryKey($source, $table);
            $duplicatePrimary = 0;
            if ($primary !== []) {
                $group = implode(', ', array_map($this->quote(...), $primary));
                $duplicatePrimary = (int) $source->querySingle("SELECT COUNT(*) FROM (SELECT 1 FROM {$this->quote($table)} GROUP BY {$group} HAVING COUNT(*) > 1)");
            }
            if ($duplicatePrimary !== 0) {
                throw new RuntimeException("Duplicate source primary key in {$table}.");
            }
            $minimum = $primary === ['id'] ? $source->querySingle('SELECT MIN(id) FROM '.$this->quote($table)) : null;
            $maximum = $primary === ['id'] ? $source->querySingle('SELECT MAX(id) FROM '.$this->quote($table)) : null;
            if ($primary === ['id'] && [$minimum, $maximum] !== [$target->table($table)->min('id'), $target->table($table)->max('id')]) {
                throw new RuntimeException("ID range mismatch for {$table}.");
            }
            $tables[$table] = ['source' => $sourceCount, 'target' => $targetCount, 'primary_columns' => $primary, 'duplicate_primary' => 0, 'min_id' => $minimum, 'max_id' => $maximum];
        }

        $excluded = [];
        foreach (SqliteToPostgreSqlPolicy::EXCLUDED as $table) {
            $excluded[$table] = ['source' => (int) $source->querySingle('SELECT COUNT(*) FROM '.$this->quote($table)), 'target' => $target->table($table)->count()];
            if ($excluded[$table]['target'] !== 0) {
                throw new RuntimeException("Excluded target table {$table} is populated.");
            }
        }
        $unvalidatedForeignKeys = (int) $target->selectOne("SELECT COUNT(*) count FROM pg_constraint WHERE contype='f' AND NOT convalidated")->count;
        if ($unvalidatedForeignKeys !== 0) {
            throw new RuntimeException('Target contains unvalidated foreign keys.');
        }
        $missingParents = (int) $target->selectOne('SELECT COUNT(*) count FROM categories child LEFT JOIN categories parent ON parent.id=child.parent_id WHERE child.parent_id IS NOT NULL AND parent.id IS NULL')->count;
        if ($missingParents !== 0) {
            throw new RuntimeException('Category hierarchy contains missing parents.');
        }
        $migrationCount = $target->table('migrations')->count();
        if ($migrationCount !== 26) {
            throw new RuntimeException('Target migration count mismatch.');
        }

        $checks = [
            'users' => $this->countPair($source, $target, 'users'),
            'products' => $this->countPair($source, $target, 'products'),
            'categories' => $this->countPair($source, $target, 'categories'),
            'product_prices' => $this->countAndTotal($source, $target, 'product_prices', 'price'),
            'stock_quantity' => $this->countAndTotal($source, $target, 'stock_balances', 'quantity'),
            'stock_reserved' => $this->countAndTotal($source, $target, 'stock_balances', 'reserved_quantity'),
            'customers' => $this->countPair($source, $target, 'customers'),
            'orders' => $this->countAndTotal($source, $target, 'orders', 'total_amount'),
            'order_items' => $this->countAndTotal($source, $target, 'order_items', 'total'),
        ];
        foreach (['warehouses', 'currencies', 'units', 'tax_profiles', 'storefront_themes', 'promotions', 'banners', 'system_addons', 'system_addon_settings', 'system_addon_events'] as $table) {
            $checks[$table] = $this->countPair($source, $target, $table);
        }
        foreach ($checks as $name => $check) {
            if ($check['source'] !== $check['target'] || (isset($check['source_total']) && $check['source_total'] !== $check['target_total'])) {
                throw new RuntimeException("Business check mismatch: {$name}.");
            }
        }

        return [
            'state' => 'verified_non_default',
            'migrations' => $migrationCount,
            'tables' => $tables,
            'excluded' => $excluded,
            'seeded_replacements' => $importResult['seeded_replacements'],
            'category_parents_restored' => $importResult['category_parents_restored'],
            'missing_category_parents' => 0,
            'foreign_key_violations' => 0,
            'unvalidated_foreign_keys' => 0,
            'unique_constraint_violations' => 0,
            'sequences' => $importResult['sequences'],
            'business_checks' => $checks,
        ];
    }

    private function countPair(SQLite3 $source, ConnectionInterface $target, string $table): array
    {
        return ['source' => (int) $source->querySingle('SELECT COUNT(*) FROM '.$this->quote($table)), 'target' => $target->table($table)->count()];
    }

    private function countAndTotal(SQLite3 $source, ConnectionInterface $target, string $table, string $column): array
    {
        $sourceValues = [];
        $result = $source->query("SELECT CAST({$this->quote($column)} AS TEXT) value FROM {$this->quote($table)} WHERE {$this->quote($column)} IS NOT NULL");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sourceValues[] = $row['value'];
        }
        $targetValues = array_map(fn ($row) => $row->value, $target->select("SELECT {$this->quote($column)}::text value FROM {$this->quote($table)} WHERE {$this->quote($column)} IS NOT NULL"));

        return $this->countPair($source, $target, $table) + ['source_total' => $this->sumDecimals($sourceValues), 'target_total' => $this->sumDecimals($targetValues)];
    }

    private function sumDecimals(array $values): string
    {
        $scale = 6;
        $sum = 0;
        foreach ($values as $value) {
            if (! preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/', (string) $value, $matches)) {
                throw new RuntimeException('Invalid decimal during verification.');
            }
            $fraction = substr(str_pad($matches[3] ?? '', $scale, '0'), 0, $scale);
            $units = ((int) $matches[2] * 10 ** $scale) + (int) $fraction;
            $sum += $matches[1] === '-' ? -$units : $units;
        }
        $sign = $sum < 0 ? '-' : '';
        $absolute = abs($sum);

        return $sign.intdiv($absolute, 10 ** $scale).'.'.str_pad((string) ($absolute % 10 ** $scale), $scale, '0', STR_PAD_LEFT);
    }

    private function primaryKey(SQLite3 $source, string $table): array
    {
        $columns = [];
        $result = $source->query('PRAGMA table_info('.$this->quote($table).')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ((int) $row['pk'] > 0) {
                $columns[(int) $row['pk']] = $row['name'];
            }
        }
        ksort($columns);

        return array_values($columns);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
