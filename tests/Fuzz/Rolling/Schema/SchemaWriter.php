<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Runs the migrations between schemas through Laravel's schema builder, as an application's
 * migrations would, with views and triggers written in each database's own SQL. A migration drops
 * every view and trigger first and creates those of the new schema last, so tables can be rebuilt
 * in between.
 */
final readonly class SchemaWriter
{
    private Builder $builder;

    private Grammar $grammar;

    private Driver $driver;

    public function __construct(private Connection $connection)
    {
        $this->builder = $connection->getSchemaBuilder();
        $this->grammar = $connection->getQueryGrammar();
        $this->driver = Driver::of($connection->getDriverName());
    }

    public function migrate(Schema $from, Schema $to): void
    {
        foreach ($from->triggers as $trigger) {
            $this->dropTrigger($trigger);
        }

        foreach (array_reverse($from->views) as $view) {
            $this->connection->statement('drop view if exists '.$this->grammar->wrapTable($view->name));
        }

        foreach ($from->foreignKeys as $name => $foreignKey) {
            if (($to->foreignKeys[$name] ?? null) != $foreignKey && isset($to->tables[$foreignKey->child])) {
                $this->builder->table($foreignKey->child, static fn (Blueprint $table) => $table->dropForeign($foreignKey->childColumns));
            }
        }

        $dropped = array_diff_key($from->tables, $to->tables);
        if ($dropped !== []) {
            $this->builder->withoutForeignKeyConstraints(function () use ($dropped): void {
                foreach ($dropped as $table) {
                    $this->builder->drop($table->name);
                }
            });
        }

        foreach (array_diff_key($to->tables, $from->tables) as $table) {
            $this->builder->create($table->name, function (Blueprint $blueprint) use ($table, $to): void {
                foreach ($table->columns as $column) {
                    $this->column($blueprint, $table, $column);
                }

                if (count($table->primaryKey) > 1) {
                    $blueprint->primary($table->primaryKey);
                }

                if ($this->driver === Driver::Sqlite) {
                    foreach ($to->foreignKeysOf($table->name) as $foreignKey) {
                        $this->foreignKey($blueprint, $foreignKey);
                    }
                }
            });
        }

        foreach (array_intersect_key($to->tables, $from->tables) as $name => $table) {
            $before = $from->tables[$name];
            $added = array_values(array_filter($table->columns, static fn (Column $column): bool => ! $before->hasColumn($column->name)));
            $removed = array_values(array_diff($before->columnNames(), $table->columnNames()));

            if ($added !== [] || $removed !== []) {
                $this->builder->table($name, function (Blueprint $blueprint) use ($table, $before, $added, $removed): void {
                    foreach ($removed as $column) {
                        if (in_array($column, $before->unique, true)) {
                            $blueprint->dropUnique([$column]);
                        }
                    }

                    if ($removed !== []) {
                        $blueprint->dropColumn($removed);
                    }

                    foreach ($added as $column) {
                        $this->column($blueprint, $table, $column);
                    }
                });
            }
        }

        foreach ($to->foreignKeys as $name => $foreignKey) {
            $created = $this->driver === Driver::Sqlite && ! isset($from->tables[$foreignKey->child]);

            if (($from->foreignKeys[$name] ?? null) != $foreignKey && ! $created) {
                $this->builder->table($foreignKey->child, fn (Blueprint $table) => $this->foreignKey($table, $foreignKey));
            }
        }

        foreach ($to->tables as $name => $table) {
            if (in_array('ref', $table->unique, true) && isset($from->tables[$name]) && ! $from->tables[$name]->hasColumn('ref')) {
                $this->backfillIdentifiers($table);
            }
        }

        foreach ($to->views as $view) {
            $this->connection->statement('create view '.$this->grammar->wrapTable($view->name).' as '.$this->viewQuery($to, $view));
        }

        foreach ($to->triggers as $trigger) {
            $this->createTrigger($trigger);
        }
    }

    /**
     * Gives the existing rows of a table that gained a public identifier the identifier their key
     * implies, row by row through the query builder, as a backfill job does.
     */
    private function backfillIdentifiers(Table $table): void
    {
        if (count($table->primaryKey) !== 1) {
            return;
        }

        $key = $table->primaryKey[0];

        foreach ($this->connection->table($table->name)->orderBy($key)->pluck($key) as $value) {
            $this->connection->table($table->name)->where($key, $value)->update(['ref' => Identifier::of($value, $table->column($key)->kind)]);
        }
    }

    /**
     * Drops every table and view, whatever schema the database is left with. SQLite's tables are
     * dropped one by one, since emptying the database file breaks connections that pods hold to
     * it in WAL mode.
     */
    public function dropEverything(): void
    {
        $this->builder->dropAllViews();

        if ($this->driver !== Driver::Sqlite) {
            $this->builder->withoutForeignKeyConstraints(fn () => $this->builder->dropAllTables());

            return;
        }

        $this->builder->withoutForeignKeyConstraints(function (): void {
            foreach ($this->connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
                $this->connection->statement('drop table '.$this->grammar->wrap($table->name));
            }
        });
    }

    private function column(Blueprint $blueprint, Table $table, Column $column): void
    {
        if ($table->autoIncrement && $table->primaryKey === [$column->name]) {
            $blueprint->id($column->name);

            return;
        }

        $definition = $column->kind === ValueKind::Integer ? $blueprint->unsignedBigInteger($column->name) : $blueprint->string($column->name, 32);
        $definition->nullable($column->nullable);

        if ($column->default !== null) {
            $definition->default($column->default);
        }

        if ($table->primaryKey === [$column->name]) {
            $definition->primary();
        }

        if (in_array($column->name, $table->unique, true)) {
            $definition->unique();
        }
    }

    private function foreignKey(Blueprint $blueprint, ForeignKey $foreignKey): void
    {
        $blueprint->foreign($foreignKey->childColumns)
            ->references($foreignKey->parentColumns)
            ->on($foreignKey->parent)
            ->onDelete($foreignKey->onDelete->value)
            ->onUpdate($foreignKey->onUpdate->value);
    }

    private function viewQuery(Schema $schema, View $view): string
    {
        $columns = fn (array $names, string $alias = ''): string => implode(', ', array_map(fn (string $name): string => $this->grammar->wrap($alias === '' ? $name : "{$alias}.{$name}"), $names));

        if ($view->kind === ViewKind::Projection) {
            $table = $schema->table($view->source);

            return 'select '.$columns([...$table->primaryKey, 'label']).' from '.$this->grammar->wrapTable($table->name);
        }

        if ($view->kind === ViewKind::OverView) {
            return 'select * from '.$this->grammar->wrapTable($view->source);
        }

        $foreignKey = $schema->foreignKey($view->source);

        if ($view->kind === ViewKind::ChildCount) {
            return 'select '.$columns($foreignKey->childColumns).', count(*) as '.$this->grammar->wrap('n')
                .' from '.$this->grammar->wrapTable($foreignKey->child).' group by '.$columns($foreignKey->childColumns);
        }

        $conditions = array_map(
            fn (string $childColumn, string $parentColumn): string => $this->grammar->wrap("c.{$childColumn}").' = '.$this->grammar->wrap("p.{$parentColumn}"),
            $foreignKey->childColumns,
            $foreignKey->parentColumns,
        );
        $keys = array_map(fn (string $column): string => $this->grammar->wrap("c.{$column}").' as '.$this->grammar->wrap("c_{$column}"), $schema->table($foreignKey->child)->primaryKey);

        return 'select '.implode(', ', $keys).', '.$this->grammar->wrap('p.label').' as '.$this->grammar->wrap('parent_label')
            .' from '.$this->grammar->wrapTable("{$foreignKey->child} as c").' join '.$this->grammar->wrapTable("{$foreignKey->parent} as p")
            .' on '.implode(' and ', $conditions);
    }

    private function createTrigger(Trigger $trigger): void
    {
        $name = $this->grammar->wrapTable($trigger->name);
        $table = $this->grammar->wrapTable($trigger->table);
        $of = $trigger->updateOf === [] ? '' : ' of '.implode(', ', array_map($this->grammar->wrap(...), $trigger->updateOf));
        $body = $this->triggerStatement($trigger);

        match ($this->driver) {
            Driver::Sqlite => $this->connection->unprepared("create trigger {$name} after {$trigger->event->value}{$of} on {$table} for each row begin {$body}; end"),
            Driver::MySql, Driver::MariaDb => $this->connection->unprepared("create trigger {$name} after {$trigger->event->value} on {$table} for each row {$body}"),
            Driver::PgSql => $this->connection->unprepared(
                "create or replace function {$this->function($trigger)}() returns trigger language plpgsql as \$\$ begin {$body}; return null; end \$\$;"
                ." create trigger {$name} after {$trigger->event->value}{$of} on {$table} for each ".($trigger->perStatement ? 'statement' : 'row')." execute function {$this->function($trigger)}()"
            ),
        };
    }

    private function triggerStatement(Trigger $trigger): string
    {
        $target = $this->grammar->wrapTable($trigger->target);
        $source = $trigger->match === null ? null : $trigger->event->rowReference().'.'.$this->grammar->wrap($trigger->match['source']);
        $note = $this->grammar->quoteString($trigger->name);
        $where = $trigger->match === null || $source === null ? '' : ' where '.$this->grammar->wrap($trigger->match['target']).' = '.$source;

        return match ($trigger->action) {
            TriggerAction::InsertAudit => "insert into {$target} ({$this->grammar->wrap('note')}, {$this->grammar->wrap('ref')}) values ({$note}, ".($source ?? 'null').')',
            TriggerAction::UpdateTarget => "update {$target} set {$this->grammar->wrap('label')} = {$note}{$where}",
            TriggerAction::DeleteTarget => "delete from {$target}{$where}",
        };
    }

    private function dropTrigger(Trigger $trigger): void
    {
        $name = $this->grammar->wrapTable($trigger->name);

        match ($this->driver) {
            Driver::Sqlite, Driver::MySql, Driver::MariaDb => $this->connection->statement("drop trigger if exists {$name}"),
            Driver::PgSql => $this->connection->unprepared("drop trigger if exists {$name} on {$this->grammar->wrapTable($trigger->table)}; drop function if exists {$this->function($trigger)}()"),
        };
    }

    private function function(Trigger $trigger): string
    {
        return $this->grammar->wrapTable("{$trigger->name}_fn");
    }
}
