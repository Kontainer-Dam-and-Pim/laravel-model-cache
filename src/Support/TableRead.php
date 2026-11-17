<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

/**
 * A table a read depends on, with the values its conditions pin each column to. The lookup script
 * tags the read with the rows of the table's primary key when the read pins that column, and with
 * the whole table otherwise, the key and its type read from the dependency map so every host
 * agrees on them. It also takes the table's cascade tags, which the pinned columns keep apart from
 * cascades that change only rows with other values in them.
 */
final readonly class TableRead
{
    /**
     * @param  string  $database  the database the table's tags are named by
     * @param  string  $table  the table's tagged name
     * @param  array<string, ColumnKeys>  $pins  lowercase column => keys of the values it is pinned to
     */
    public function __construct(
        public string $database,
        public string $table,
        public array $pins,
    ) {}
}
