<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use UnexpectedValueException;

/**
 * Extracts the values a query's conditions pin columns to, so its tags can name the rows it reads
 * or writes once the scripts know which column is the table's primary key.
 *
 * A table the statement reads more than once, because it is joined to itself or named again in a
 * subquery, is left out: such a query reads rows its conditions do not pin, so pinning any of
 * them would leave the result stale when one of the others changes.
 */
final class RowExtractor
{
    /**
     * The values each column of each table is pinned to by `col = v` or `col IN (...)` wheres
     * the query ANDs together, so every row it reads from that table has one of them. Wheres
     * joined by OR pin only when every one of them pins the same column, to the union of their
     * values. In a query with joins only qualified columns pin, since an unqualified one may
     * belong to any joined table. Tables the statement reads more than once are left out.
     *
     * @param  array<string, int>  $referenceCounts  how often the statement reads each table, see
     *                                               `SqlTableScanner::tableReferenceCounts()`
     * @return array<string, array<string, list<scalar>>> table => lowercase column => values
     */
    public static function extractColumnPins(BaseBuilder $builder, array $referenceCounts = []): array
    {
        $wheres = self::wheres($builder);
        $aliasMap = self::resolveAliasMap($builder);
        $requireQualified = ($builder->joins ?? []) !== [];
        $hasOr = false;
        $matches = [];

        foreach ($wheres as $where) {
            $hasOr = $hasOr || str_starts_with(self::boolean($where), 'or');
            $matches[] = self::matchColumnPin($builder, $where, $aliasMap, $requireQualified);
        }

        if ($hasOr && ! self::pinOneColumn($matches)) {
            return [];
        }

        $pins = [];
        foreach (array_filter($matches) as [$table, $column, $values]) {
            $pins[$table][$column] = [...$pins[$table][$column] ?? [], ...$values];
        }

        return array_filter($pins, static fn (string $table): bool => ($referenceCounts[$table] ?? 1) <= 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: non-empty-list<scalar>}|null>  $matches
     */
    private static function pinOneColumn(array $matches): bool
    {
        $pinned = [];

        foreach ($matches as $match) {
            if ($match === null) {
                return false;
            }

            $pinned[$match[0]."\0".$match[1]] = true;
        }

        return count($pinned) === 1;
    }

    /**
     * @param  array<mixed>  $where
     * @param  array<string, string>  $aliasMap
     * @return array{0: string, 1: string, 2: non-empty-list<scalar>}|null
     */
    private static function matchColumnPin(BaseBuilder $builder, array $where, array $aliasMap, bool $requireQualified): ?array
    {
        if (! isset($where['column']) || str_contains(self::boolean($where), 'not')) {
            return null;
        }

        $columnRef = $where['column'] instanceof Expression ? $builder->getGrammar()->getValue($where['column']) : $where['column'];

        if (! is_string($columnRef) || ($requireQualified && ! str_contains($columnRef, '.'))) {
            return null;
        }

        if (! str_contains($columnRef, '.') && is_string($builder->from)) {
            $columnRef = SqlAliasParser::stripAlias($builder->from).'.'.$columnRef;
        }

        [$table, $column] = self::splitTableAndColumn($columnRef);

        if ($table === null) {
            return null;
        }

        $table = $aliasMap[$table] ?? $table;
        $values = match (true) {
            ($where['type'] ?? null) === 'Basic' && ($where['operator'] ?? '=') === '=' && is_scalar($where['value'] ?? null) => [$where['value']],
            in_array($where['type'] ?? null, ['In', 'InRaw'], true) && is_array($where['values'] ?? null) => array_values($where['values']),
            default => [],
        };

        if ($values === [] || array_filter($values, 'is_scalar') !== $values) {
            return null;
        }

        return [$table, Write::columnName($column), $values];
    }

    /**
     * Laravel stores the boolean as given, so it may be 'or', 'and not' or an upper-case variant.
     *
     * @param  array<mixed>  $where
     */
    private static function boolean(array $where): string
    {
        $boolean = $where['boolean'] ?? 'and';

        return is_string($boolean) ? strtolower($boolean) : throw new UnexpectedValueException('Where clause boolean must be a string, got '.get_debug_type($boolean));
    }

    /**
     * @return list<array<mixed>>
     */
    private static function wheres(BaseBuilder $builder): array
    {
        return array_values(array_filter($builder->wheres, 'is_array'));
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function splitTableAndColumn(string $identifier): array
    {
        if (! str_contains($identifier, '.')) {
            return [null, $identifier];
        }

        $parts = explode('.', $identifier);
        $column = array_pop($parts);
        $table = end($parts) ?: null;

        return [$table, $column];
    }

    /**
     * @return array<string, string>
     */
    private static function resolveAliasMap(BaseBuilder $builder): array
    {
        $map = [];

        $from = $builder->from;
        if (is_string($from)) {
            $alias = SqlAliasParser::extractAlias($from);
            if ($alias !== null) {
                $map[$alias] = SqlAliasParser::stripAlias($from);
            }
        }

        foreach (TableExtractor::joinClauses($builder) as $join) {
            if (is_string($join->table)) {
                $alias = SqlAliasParser::extractAlias($join->table);
                if ($alias !== null) {
                    $map[$alias] = SqlAliasParser::stripAlias($join->table);
                }
            }
        }

        return $map;
    }
}
