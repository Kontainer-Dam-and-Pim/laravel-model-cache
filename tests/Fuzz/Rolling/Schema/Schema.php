<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

use LogicException;

/**
 * Tables, foreign keys, views and triggers, as one version of an application's migrations leave
 * them. Removing a table or column removes what depends on it, as a migration must for the
 * database to accept it.
 */
final readonly class Schema
{
    /**
     * @param  array<string, Table>  $tables
     * @param  array<string, ForeignKey>  $foreignKeys
     * @param  array<string, View>  $views  in the order they can be created
     * @param  array<string, Trigger>  $triggers
     */
    public function __construct(
        public array $tables,
        public array $foreignKeys = [],
        public array $views = [],
        public array $triggers = [],
    ) {}

    public function table(string $name): Table
    {
        return $this->tables[$name] ?? throw new LogicException("No table {$name}");
    }

    public function foreignKey(string $name): ForeignKey
    {
        return $this->foreignKeys[$name] ?? throw new LogicException("No foreign key {$name}");
    }

    public function view(string $name): View
    {
        return $this->views[$name] ?? throw new LogicException("No view {$name}");
    }

    /**
     * @return list<ForeignKey>
     */
    public function foreignKeysOf(string $child): array
    {
        return array_values(array_filter($this->foreignKeys, static fn (ForeignKey $foreignKey): bool => $foreignKey->child === $child));
    }

    /**
     * Sets the table, and the columns of the tables inheriting from it to its own.
     */
    public function withTable(Table $table): self
    {
        $tables = [...$this->tables, $table->name => $table];

        foreach ($tables as $name => $other) {
            if ($other->inherits === $table->name) {
                $tables[$name] = new Table($name, $table->columns, $table->primaryKey, $table->autoIncrement, [], $table->name);
            }
        }

        return new self($tables, $this->foreignKeys, $this->views, $this->triggers);
    }

    public function withForeignKey(ForeignKey $foreignKey): self
    {
        return new self($this->tables, [...$this->foreignKeys, $foreignKey->name => $foreignKey], $this->views, $this->triggers);
    }

    public function withView(View $view): self
    {
        return new self($this->tables, $this->foreignKeys, [...$this->views, $view->name => $view], $this->triggers);
    }

    public function withTrigger(Trigger $trigger): self
    {
        return new self($this->tables, $this->foreignKeys, $this->views, [...$this->triggers, $trigger->name => $trigger]);
    }

    public function withoutForeignKey(string $name): self
    {
        $foreignKeys = $this->foreignKeys;
        unset($foreignKeys[$name]);

        return (new self($this->tables, $foreignKeys, $this->views, $this->triggers))->withoutBrokenDependents();
    }

    public function withoutView(string $name): self
    {
        $views = $this->views;
        unset($views[$name]);

        return (new self($this->tables, $this->foreignKeys, $views, $this->triggers))->withoutBrokenDependents();
    }

    public function withoutTrigger(string $name): self
    {
        $triggers = $this->triggers;
        unset($triggers[$name]);

        return new self($this->tables, $this->foreignKeys, $this->views, $triggers);
    }

    public function withoutTable(string $name): self
    {
        $tables = array_filter($this->tables, static fn (Table $table): bool => $table->name !== $name && $table->inherits !== $name);
        $foreignKeys = array_filter($this->foreignKeys, static fn (ForeignKey $foreignKey): bool => isset($tables[$foreignKey->child]) && isset($tables[$foreignKey->parent]));

        return (new self($tables, $foreignKeys, $this->views, $this->triggers))->withoutBrokenDependents();
    }

    public function withoutColumn(string $table, string $column): self
    {
        $tables = $this->withTable($this->table($table)->withoutColumn($column))->tables;
        $foreignKeys = array_filter(
            $this->foreignKeys,
            static fn (ForeignKey $foreignKey): bool => ! ($foreignKey->child === $table && in_array($column, $foreignKey->childColumns, true))
                && ! ($foreignKey->parent === $table && in_array($column, $foreignKey->parentColumns, true)),
        );

        return (new self($tables, $foreignKeys, $this->views, $this->triggers))->withoutBrokenDependents();
    }

    /**
     * Drops views and triggers reading or writing relations or columns that no longer exist,
     * repeating until views over dropped views are gone too.
     */
    private function withoutBrokenDependents(): self
    {
        $views = $this->views;

        do {
            $before = count($views);
            $schema = new self($this->tables, $this->foreignKeys, $views, $this->triggers);
            $views = array_filter($views, static fn (View $view): bool => $schema->resolves($view));
        } while (count($views) !== $before);

        $schema = new self($this->tables, $this->foreignKeys, $views, []);
        $triggers = array_filter($this->triggers, static fn (Trigger $trigger): bool => $schema->hasColumns($trigger->references()));

        return new self($this->tables, $this->foreignKeys, $views, $triggers);
    }

    private function resolves(View $view): bool
    {
        if ($view->kind === ViewKind::OverView) {
            return isset($this->views[$view->source]);
        }

        if ($view->kind !== ViewKind::Projection && ! isset($this->foreignKeys[$view->source])) {
            return false;
        }

        if ($view->kind === ViewKind::Projection && ! isset($this->tables[$view->source])) {
            return false;
        }

        return $this->hasColumns($view->references($this));
    }

    /**
     * @param  array<string, list<string>>  $references
     */
    private function hasColumns(array $references): bool
    {
        foreach ($references as $relation => $columns) {
            if (isset($this->views[$relation]) && $columns === []) {
                continue;
            }

            if (! isset($this->tables[$relation])) {
                return false;
            }

            foreach ($columns as $column) {
                if (! $this->tables[$relation]->hasColumn($column)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Every table and view a read may name.
     *
     * @return list<string>
     */
    public function relations(): array
    {
        return [...array_keys($this->tables), ...array_keys($this->views)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tables' => array_values(array_map(static fn (Table $table): array => $table->toArray(), $this->tables)),
            'foreignKeys' => array_values(array_map(static fn (ForeignKey $foreignKey): array => $foreignKey->toArray(), $this->foreignKeys)),
            'views' => array_values(array_map(static fn (View $view): array => $view->toArray(), $this->views)),
            'triggers' => array_values(array_map(static fn (Trigger $trigger): array => $trigger->toArray(), $this->triggers)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keyed = static function (array $items, callable $build): array {
            $keyed = [];
            foreach ($items as $item) {
                $value = $build($item);
                $keyed[$value->name] = $value;
            }

            return $keyed;
        };

        return new self(
            $keyed($data['tables'], Table::fromArray(...)),
            $keyed($data['foreignKeys'], ForeignKey::fromArray(...)),
            $keyed($data['views'], View::fromArray(...)),
            $keyed($data['triggers'], Trigger::fromArray(...)),
        );
    }
}
