<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

/**
 * A table a write changes, with every column whose values name all the rows it may have changed:
 * a column its conditions pin and that it assigns no other value than keyed ones, or a column an
 * insert gives a keyed value in every row. The invalidation script replaces the row tags of those
 * values when the column is the table's primary key, as the dependency map lists it, and the
 * table's row-pinned reads as a whole otherwise.
 */
final readonly class RowWrite
{
    /**
     * @param  array<string, ColumnKeys>  $columns  lowercase column => keys of every value of it the changed rows have, had or got
     */
    public function __construct(
        public string $database,
        public string $table,
        public array $columns,
    ) {}

    /**
     * What the two writes change together: only columns naming the rows of both stay precise.
     */
    public function merge(self $other): self
    {
        $columns = [];

        foreach (array_intersect_key($this->columns, $other->columns) as $column => $keys) {
            $merged = $keys->merge($other->columns[$column]);

            if (! $merged->isEmpty()) {
                $columns[$column] = $merged;
            }
        }

        return new self($this->database, $this->table, $columns);
    }

    public function size(): int
    {
        return array_sum(array_map(static fn (ColumnKeys $keys): int => $keys->size(), $this->columns));
    }
}
