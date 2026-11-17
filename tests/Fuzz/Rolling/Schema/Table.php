<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

use LogicException;

/**
 * A table: its columns in order, its primary key, whether that key is generated, the columns
 * with a unique index of their own, which foreign keys and models may use as a key instead, and
 * on PostgreSQL the table it inherits its columns from. An inheriting table has no constraints of
 * its own: its primary key names the columns that would be one.
 */
final readonly class Table
{
    /**
     * @param  list<Column>  $columns
     * @param  non-empty-list<string>  $primaryKey
     * @param  list<string>  $unique
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey,
        public bool $autoIncrement,
        public array $unique = [],
        public ?string $inherits = null,
    ) {}

    public function column(string $name): Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        throw new LogicException("Table {$this->name} has no column {$name}");
    }

    public function hasColumn(string $name): bool
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(static fn (Column $column): string => $column->name, $this->columns);
    }

    /**
     * Single columns whose values name one row each: a single-column primary key and the unique
     * columns.
     *
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return [...(count($this->primaryKey) === 1 ? $this->primaryKey : []), ...$this->unique];
    }

    public function withColumn(Column $column, bool $unique = false): self
    {
        return new self($this->name, [...$this->columns, $column], $this->primaryKey, $this->autoIncrement, $unique ? [...$this->unique, $column->name] : $this->unique, $this->inherits);
    }

    public function withoutColumn(string $name): self
    {
        if (in_array($name, $this->primaryKey, true)) {
            throw new LogicException("Cannot drop primary key column {$name} of {$this->name}");
        }

        return new self(
            $this->name,
            array_values(array_filter($this->columns, static fn (Column $column): bool => $column->name !== $name)),
            $this->primaryKey,
            $this->autoIncrement,
            array_values(array_diff($this->unique, [$name])),
            $this->inherits,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(static fn (Column $column): array => $column->toArray(), $this->columns),
            'primaryKey' => $this->primaryKey,
            'autoIncrement' => $this->autoIncrement,
            'unique' => $this->unique,
            'inherits' => $this->inherits,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            array_map(Column::fromArray(...), $data['columns']),
            $data['primaryKey'],
            $data['autoIncrement'],
            $data['unique'],
            $data['inherits'],
        );
    }
}
