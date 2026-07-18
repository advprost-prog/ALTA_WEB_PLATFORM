<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use PDO;

final class PortableTextSearch
{
    /**
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, array $columns, string $term): Builder
    {
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();
        $expression = 'lower';

        if ($driver === 'sqlite') {
            $pdo = $connection->getPdo();

            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                throw new \RuntimeException('The SQLite PDO driver cannot register the required Unicode search function.');
            }

            $pdo->sqliteCreateFunction(
                'alta_casefold',
                static fn (?string $value): ?string => $value === null
                    ? null
                    : mb_convert_case($value, MB_CASE_FOLD, 'UTF-8'),
                1,
                PDO::SQLITE_DETERMINISTIC,
            );

            $expression = 'alta_casefold';
        }

        $pattern = '%'.self::escapeLikePattern(mb_convert_case($term, MB_CASE_FOLD, 'UTF-8')).'%';
        $grammar = $connection->getQueryGrammar();

        return $query->where(function (Builder $query) use ($columns, $expression, $grammar, $pattern): void {
            foreach (array_values($columns) as $index => $column) {
                $wrapped = $grammar->wrap($column);
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

                $query->{$method}("{$expression}({$wrapped}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private static function escapeLikePattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
