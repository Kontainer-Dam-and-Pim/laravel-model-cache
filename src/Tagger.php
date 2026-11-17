<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

use Kontainer\ModelCache\Support\AssignedColumns;
use Kontainer\ModelCache\Support\ColumnKeys;
use Kontainer\ModelCache\Support\RowWrite;
use Kontainer\ModelCache\Support\TableName;
use Kontainer\ModelCache\Support\TableRead;

/**
 * Generates cache tags for queries based on database, tables, and the values their conditions
 * pin columns to.
 *
 * A read pinned to rows of a table's primary key carries `table_specific` and one tag per row;
 * any other read carries `table_unspecific`. A write replaces `table_unspecific` and the tags of
 * the rows it changes, or `table_specific` when it cannot name them. Which column is the primary
 * key, and how its values compare, is read from the schema by the scripts (see
 * `LuaScripts::LOOKUP` and `LuaScripts::INVALIDATE`), never from a model, so hosts whose models
 * disagree about a table's key, such as old and new pods during a deploy, or a model and a
 * builder without one, name its rows alike. With `consider_rows` disabled no read is pinned and
 * every write replaces `table_specific`.
 */
final readonly class Tagger
{
    private const string PREFIX_DATABASE = 'tags:database:';

    private const string PREFIX_TABLE_SPECIFIC = ':table_specific:';

    private const string PREFIX_TABLE_UNSPECIFIC = ':table_unspecific:';

    /**
     * What follows a table's `table_specific` tag in the tag of one of its rows, followed by the
     * row's key.
     */
    public const string ROW_INFIX = ':row:';

    private const string PREFIX_CASCADE = ':cascade:';

    private bool $considerRows;

    private AssignedColumns $assigned;

    public function __construct(
        private Reflector $reflector,
        ?AssignedColumns $assigned = null,
    ) {
        $this->considerRows = (bool) config('model-cache.consider_rows', true);
        $this->assigned = $assigned ?? AssignedColumns::none();
    }

    /**
     * The tables of a read, with the values its wheres pin each column to when rows are considered.
     *
     * @return list<TableRead>
     */
    public function reads(): array
    {
        $reads = [];

        foreach ($this->pinnedTables() as [$database, $name, $pins]) {
            $reads[] = new TableRead($database, $name, $pins);
        }

        return $reads;
    }

    /**
     * The tags a write replaces whatever rows it changes: `table_unspecific` of each table.
     *
     * @return list<string>
     */
    public function writeTags(): array
    {
        $tags = [];

        foreach (array_filter($this->reflector->getTables(), 'is_string') as $table) {
            [$database, $name] = $this->locate($table);
            $tags[self::PREFIX_DATABASE.$database.self::PREFIX_TABLE_UNSPECIFIC.$name] = true;
        }

        return array_map('strval', array_keys($tags));
    }

    /**
     * The tables a write changes, each with the columns whose values name every row it changed.
     *
     * @return list<RowWrite>
     */
    public function rowWrites(): array
    {
        $type = $this->reflector->getType();
        $writes = [];

        foreach ($this->pinnedTables() as [$database, $name, $pins, $table]) {
            $columns = match (true) {
                ! $this->considerRows => [],
                $type === Reflector::QUERY_TYPE_INSERT => $this->assigned->insertedKeys($table),
                $type === Reflector::QUERY_TYPE_UPDATE, $type === Reflector::QUERY_TYPE_DELETE => $this->reassignedPins($table, $pins),
                default => [],
            };

            ksort($columns, SORT_STRING);
            $writes[$database."\0".$name] = new RowWrite($database, $name, $columns);
        }

        return array_values($writes);
    }

    /**
     * The pinned columns that still name every changed row after the write: those it does not
     * assign, and those it assigns keyed values, whose keys are added.
     *
     * @param  array<string, ColumnKeys>  $pins
     * @return array<string, ColumnKeys>
     */
    private function reassignedPins(string $table, array $pins): array
    {
        if (! $this->assigned->isKnown()) {
            return [];
        }

        $columns = [];

        foreach ($pins as $column => $keys) {
            if (! $this->assigned->assigns($table, $column)) {
                $columns[$column] = $keys;

                continue;
            }

            $assigned = $this->assigned->keysOf($table, $column);
            $merged = $assigned === null ? null : $keys->merge($assigned);

            if ($merged !== null && ! $merged->isEmpty()) {
                $columns[$column] = $merged;
            }
        }

        return $columns;
    }

    /**
     * Each table of the query with its database, tagged name, the keys of the values its columns
     * are pinned to, and its name as the query writes it.
     *
     * @return list<array{string, string, array<string, ColumnKeys>, string}>
     */
    private function pinnedTables(): array
    {
        $pinned = $this->considerRows ? $this->reflector->getColumnPins() : [];
        $tables = [];

        foreach (array_filter($this->reflector->getTables(), 'is_string') as $table) {
            [$database, $name] = $this->locate($table);
            $columns = [];

            foreach ($pinned[$table] ?? [] as $column => $values) {
                $keys = ColumnKeys::of($values);
                if (! $keys->isEmpty()) {
                    $columns[$column] = $keys;
                }
            }

            ksort($columns, SORT_STRING);
            $tables[] = [$database, $name, $columns, $table];
        }

        return $tables;
    }

    /**
     * Prefix of the tags foreign key cascades into a table replace, followed by the table name
     * and `:any`, `:via:<column>` or `:col:<column>:<key>` (see `LuaScripts::CASCADE_TAGS`).
     */
    public static function cascadeTagPrefix(string $database): string
    {
        return self::PREFIX_DATABASE.$database.self::PREFIX_CASCADE;
    }

    /**
     * Prefix of the `table_specific` tag of a table and of the tags of its rows, followed by the
     * table name.
     */
    public static function tableSpecificTagPrefix(string $database): string
    {
        return self::PREFIX_DATABASE.$database.self::PREFIX_TABLE_SPECIFIC;
    }

    /**
     * Prefix of the `table_unspecific` tag of a table, followed by the table name.
     */
    public static function tableUnspecificTagPrefix(string $database): string
    {
        return self::PREFIX_DATABASE.$database.self::PREFIX_TABLE_UNSPECIFIC;
    }

    /**
     * Splits a qualified table name into the database and table it tags. MySQL qualifiers name
     * databases, so `reports.sales` shares tags with `sales` written on a connection to
     * `reports`; elsewhere they name schemas within the connection's database.
     *
     * @return array{string, string}
     */
    private function locate(string $table): array
    {
        $segments = explode('.', $table);

        $name = TableName::tagged((string) array_pop($segments));

        if ($segments === [] || ! $this->reflector->qualifiersNameDatabases()) {
            return [$this->reflector->getDatabase(), $name];
        }

        return [(string) end($segments), $name];
    }

    /**
     * Prefixes that, followed by a table name, form every tag a read of that table can carry
     * at table level, so appending a table name invalidates all its cached reads. Both are
     * returned whatever `consider_rows` says, so hosts configured differently, such as old and
     * new pods during a deploy, still invalidate each other's reads.
     *
     * @return list<string>
     */
    public static function tableWideTagPrefixes(string $database): array
    {
        return [self::tableSpecificTagPrefix($database), self::tableUnspecificTagPrefix($database)];
    }

    /**
     * Tags invalidating every cached read of the query's tables, a superset of what any write to
     * them invalidates.
     *
     * @return list<string>
     */
    public function tableWideTags(): array
    {
        $tags = [];

        foreach (array_filter($this->reflector->getTables(), 'is_string') as $table) {
            [$database, $name] = $this->locate($table);

            foreach (self::tableWideTagPrefixes($database) as $prefix) {
                $tags[$prefix.$name] = true;
            }
        }

        return array_map('strval', array_keys($tags));
    }
}
