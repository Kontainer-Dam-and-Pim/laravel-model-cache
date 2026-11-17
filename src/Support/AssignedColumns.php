<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

/**
 * The values a write gives the columns of the table it targets, so its row tags can name the rows
 * it changes whichever column turns out to be the primary key. A column it assigns a value that
 * cannot be keyed, an expression, a JSON path or a column qualified by a table name is uncertain;
 * an insert's column is uncertain unless every row gives it a keyed value. Other tables a write
 * names, such as joined ones, may receive any of its assignments, so for them every assigned
 * column is uncertain.
 */
final readonly class AssignedColumns
{
    /**
     * @param  array<string, ColumnKeys|null>  $columns  lowercase column => keys of its values, null when uncertain
     */
    private function __construct(
        public ?string $table,
        public bool $insertsRows,
        private array $columns,
        private bool $known,
    ) {}

    /**
     * A write assigning nothing, such as a delete.
     */
    public static function none(): self
    {
        return new self(null, false, [], true);
    }

    /**
     * A write whose assigned values are not known, such as an insert from a subquery.
     */
    public static function unknown(): self
    {
        return new self(null, false, [], false);
    }

    /**
     * @param  array<int|string, mixed>  $values  one row or a list of rows, as `Builder::insert()` takes them
     */
    public static function inserted(?string $table, array $values): self
    {
        if ($table === null) {
            return self::unknown();
        }

        $rows = is_array(reset($values)) ? array_values($values) : [$values];
        $byColumn = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return self::unknown();
            }

            foreach ($row as $column => $value) {
                $byColumn[(string) $column][] = $value;
            }
        }

        $columns = [];
        foreach ($byColumn as $column => $columnValues) {
            $name = Write::columnName($column);
            $columns[$name] = count($columnValues) === count($rows) && ! self::isQualified($column) && ! array_key_exists($name, $columns) ? self::keyedOrUncertain($columnValues) : null;
        }

        return new self($table, true, $columns, true);
    }

    /**
     * @param  array<int|string, mixed>  $values  column => value, as `Builder::update()` takes them
     */
    public static function updated(?string $table, array $values): self
    {
        if ($table === null) {
            return self::unknown();
        }

        $columns = [];
        foreach ($values as $column => $value) {
            $name = Write::columnName((string) $column);
            $columns[$name] = self::isQualified((string) $column) || array_key_exists($name, $columns) ? null : self::keyedOrUncertain([$value]);
        }

        return new self($table, false, $columns, true);
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    public function assigns(string $table, string $column): bool
    {
        return ! $this->known || ($table !== $this->table && $this->columns !== []) || array_key_exists($column, $this->columns);
    }

    /**
     * The keys of the values the column of the table is assigned, null when they are uncertain
     * or the column is not assigned.
     */
    public function keysOf(string $table, string $column): ?ColumnKeys
    {
        return $this->known && $table === $this->table ? $this->columns[$column] ?? null : null;
    }

    /**
     * Every column of the table each inserted row gives a keyed value.
     *
     * @return array<string, ColumnKeys>
     */
    public function insertedKeys(string $table): array
    {
        if (! $this->known || ! $this->insertsRows || $table !== $this->table) {
            return [];
        }

        return array_filter($this->columns, static fn (?ColumnKeys $keys): bool => $keys !== null);
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function keyedOrUncertain(array $values): ?ColumnKeys
    {
        $keys = ColumnKeys::of($values);

        return $keys->isEmpty() ? null : $keys;
    }

    private static function isQualified(string $column): bool
    {
        return str_contains($column, '.') || str_contains($column, '->');
    }
}
