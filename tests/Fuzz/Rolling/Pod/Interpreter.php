<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Closure;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Api;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Condition;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Operator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\RawStyle;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\ReadShape;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\WriteKind;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ForeignKey;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Table;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\ValueKind;
use PDOException;

/**
 * Runs a release's reads and writes in a pod. Every read runs through the cache and again
 * without it; each outcome is the rows in a canonical order, or the SQLSTATE the database
 * rejected the query with, so the coordinator can compare them exactly.
 */
final readonly class Interpreter
{
    public function __construct(private Release $release) {}

    /**
     * @return array{cached: array<string, mixed>, database: array<string, mixed>}
     */
    public function read(Read $read): array
    {
        return [
            'cached' => self::outcome(self::isolated(fn (): mixed => $this->runRead($read, true))),
            'database' => self::outcome(self::isolated(fn (): mixed => $this->runRead($read, false))),
        ];
    }

    /**
     * The read through the cache only, as the application runs it.
     */
    public function cachedRead(Read $read): mixed
    {
        return $this->runRead($read, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function write(Write $write): array
    {
        return self::outcome(self::isolated(fn (): mixed => $this->runWrite($write)));
    }

    /**
     * Inside a transaction a statement runs in a savepoint of its own, as code catching a failed
     * statement in a nested `DB::transaction()` does, so one rejected on PostgreSQL does not
     * abort the transaction for those after it.
     *
     * @param  Closure(): mixed  $run
     * @return Closure(): mixed
     */
    private static function isolated(Closure $run): Closure
    {
        return DB::transactionLevel() > 0 ? static fn (): mixed => DB::transaction($run) : $run;
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(TransactionAction $action): array
    {
        return self::outcome(static fn (): mixed => match ($action) {
            TransactionAction::Begin => DB::beginTransaction(),
            TransactionAction::Commit => DB::commit(),
            TransactionAction::Rollback => DB::rollBack(),
        });
    }

    /**
     * Commits every transaction the pod left open.
     *
     * @return array<string, mixed>
     */
    public function settle(): array
    {
        return self::outcome(static function (): int {
            $levels = DB::transactionLevel();
            while (DB::transactionLevel() > 0) {
                DB::commit();
            }

            return $levels;
        });
    }

    /**
     * @param  Closure(): mixed  $run
     * @return array<string, mixed>
     */
    private static function outcome(Closure $run): array
    {
        try {
            return ['result' => $run()];
        } catch (QueryException|PDOException $exception) {
            $state = $exception instanceof QueryException ? ($exception->errorInfo[0] ?? $exception->getCode()) : $exception->getCode();

            return ['error' => (string) $state, 'message' => strtok($exception->getMessage(), "\n")];
        }
    }

    private function runRead(Read $read, bool $cached): mixed
    {
        if ($read->api === Api::Model) {
            return $this->runModelRead($read, $cached);
        }

        if ($read->shape === ReadShape::GroupedChildCounts) {
            return $this->runGroupedChildCounts($read, $cached);
        }

        $query = $this->table($read->relation, $cached);
        $joins = in_array($read->shape, [ReadShape::JoinParent, ReadShape::JoinedChildCounts], true);
        $this->shape($query, $read);

        $read->api === Api::Raw
            ? $this->applyRawConditions($query, $read->conditions, $read->or, $joins ? $read->relation : null)
            : $this->applyConditions($query, $read->conditions, $read->or, $joins ? $read->relation : null, false);

        return match ($read->shape) {
            ReadShape::Count => $query->count(),
            ReadShape::Sum => $query->sum('amount'),
            ReadShape::Max => $query->max('amount'),
            default => self::canonical($query->get()->all()),
        };
    }

    private function runModelRead(Read $read, bool $cached): mixed
    {
        $query = $this->modelQuery($read->relation, $cached);
        $this->applyConditions($query, $read->conditions, $read->or, $read->relation, true);
        $relation = (string) $read->subject;

        match ($read->shape) {
            ReadShape::EagerLoad => $query->with([$relation => static fn (Relation $related) => $cached ? $related : $related->withoutCache()]),
            ReadShape::WithCount => $query->withCount($relation),
            ReadShape::WhereHas => $query->whereHas($relation, static fn (EloquentBuilder $related) => $related->where('label', $read->label)),
            ReadShape::WithTrashed => $query->withTrashed(),
            ReadShape::OnlyTrashed => $query->onlyTrashed(),
            default => null,
        };

        return $read->shape === ReadShape::Count ? $query->count() : self::canonical($query->get()->toArray());
    }

    /**
     * Applies the shape's joins, subqueries and selects to a builder read of its table.
     */
    private function shape(Builder $query, Read $read): void
    {
        $table = $read->relation;

        if (! $read->shape->namesForeignKey() && $read->shape !== ReadShape::Union) {
            return;
        }

        if ($read->shape === ReadShape::Union) {
            $query->select('label')->union($this->table((string) $read->subject, true)->select('label'));

            return;
        }

        $foreignKey = $this->release->schema->foreignKey((string) $read->subject);
        $grammar = DB::connection()->getQueryGrammar();
        $children = fn (): Builder => $this->table("{$foreignKey->child} as children", true)->whereColumn("children.{$foreignKey->childColumns[0]}", "{$table}.{$foreignKey->parentColumns[0]}");
        $rawChildren = "from {$grammar->wrapTable("{$foreignKey->child} as children")} where {$grammar->wrap("children.{$foreignKey->childColumns[0]}")} = {$grammar->wrap("{$table}.{$foreignKey->parentColumns[0]}")}";

        match ($read->shape) {
            ReadShape::JoinParent => $query->join("{$foreignKey->parent} as joined_parent", function ($join) use ($foreignKey, $table): void {
                foreach ($foreignKey->childColumns as $index => $column) {
                    $join->on("{$table}.{$column}", '=', "joined_parent.{$foreignKey->parentColumns[$index]}");
                }
            })->select("{$table}.*", 'joined_parent.label as parent_label'),
            ReadShape::ChildrenOfLabelledParents => $query->whereIn(
                "{$table}.{$foreignKey->childColumns[0]}",
                $this->table($foreignKey->parent, true)->select($foreignKey->parentColumns[0])->where('label', $read->label),
            ),
            ReadShape::ParentsWithChildren => $query->whereExists(static fn (Builder $exists) => $exists->select(DB::raw(1))->from("{$foreignKey->child} as children")->whereColumn("children.{$foreignKey->childColumns[0]}", "{$table}.{$foreignKey->parentColumns[0]}")),
            ReadShape::ChildCounts => $query->select("{$table}.*")->selectSub($children()->selectRaw('count(*)'), 'child_count'),
            ReadShape::JoinedChildCounts => $query->joinSub(
                $this->table($foreignKey->child, true)->select($foreignKey->childColumns[0])->selectRaw('count(*) as child_count')->groupBy($foreignKey->childColumns[0]),
                'child_counts',
                "child_counts.{$foreignKey->childColumns[0]}",
                '=',
                "{$table}.{$foreignKey->parentColumns[0]}",
            )->select("{$table}.*", 'child_counts.child_count'),
            ReadShape::MostChildren => (function () use ($query, $children, $table): void {
                $query->orderByDesc($children()->selectRaw('count(*)'));
                foreach ($this->release->schema->table($table)->primaryKey as $column) {
                    $query->orderBy("{$table}.{$column}");
                }
                $query->limit(3);
            })(),
            ReadShape::RawParentsWithChildren => $query->whereRaw("exists (select 1 {$rawChildren})"),
            ReadShape::RawChildCounts => $query->select("{$table}.*")->selectRaw("(select count(*) {$rawChildren}) as {$grammar->wrap('child_count')}"),
            default => throw new \LogicException("No builder form of {$read->shape->value}"),
        };
    }

    /**
     * How many rows of the table, selected by the read's conditions, each value of the foreign
     * key has, read from a subquery.
     */
    private function runGroupedChildCounts(Read $read, bool $cached): mixed
    {
        $column = $this->release->schema->foreignKey((string) $read->subject)->childColumns[0];
        $grouped = $this->table($read->relation, true)->select($column)->selectRaw('count(*) as child_count')->groupBy($column);
        $read->api === Api::Raw
            ? $this->applyRawConditions($grouped, $read->conditions, $read->or, null)
            : $this->applyConditions($grouped, $read->conditions, $read->or, null, false);
        $query = DB::query()->fromSub($grouped, 'grouped');

        return self::canonical(($cached ? $query : $query->withoutCache())->get()->all());
    }

    private function table(string $name, bool $cached): Builder
    {
        $query = DB::table($name);

        return $cached ? $query : $query->withoutCache();
    }

    private function runWrite(Write $write): mixed
    {
        $table = $this->release->schema->table($write->table);

        if ($write->api === Api::Raw) {
            return $this->runRawWrite($write);
        }

        $query = $write->api === Api::Model ? $this->modelQuery($write->table, true) : DB::table($write->table);

        if ($write->kind !== WriteKind::UpdateOrInsert && $write->kind !== WriteKind::InsertUsing) {
            $this->applyConditions($query, $write->conditions, $write->or, $write->table, $write->api === Api::Model);
        }

        return match ($write->kind) {
            WriteKind::Insert => $write->api === Api::Model
                ? array_map(static fn (array $row): mixed => $query->create($row)->getKey(), $write->rows)
                : $query->insert($write->rows),
            WriteKind::InsertOrIgnore => $query->insertOrIgnore($write->rows),
            WriteKind::InsertGetId => $query->insertGetId($write->values()),
            WriteKind::Upsert => $query->upsert($write->rows, $table->primaryKey, array_values(array_intersect(['label', 'amount'], array_keys($write->values())))),
            WriteKind::Update => $query->update($write->values()),
            WriteKind::Increment => $query->increment('amount', (int) $write->values()['amount']),
            WriteKind::IncrementEach => $query->incrementEach(['amount' => (int) $write->values()['amount']]),
            WriteKind::Delete => $query->delete(),
            WriteKind::Truncate => $query->truncate(),
            WriteKind::Save => self::model($query->first())?->fill($write->values())->save(),
            WriteKind::DeleteModel => self::model($query->first())?->delete(),
            WriteKind::Restore => $query->onlyTrashed()->restore(),
            WriteKind::ForceDelete => $query->withTrashed()->forceDelete(),
            WriteKind::InsertUsing => $this->insertUsing($write, $table),
            WriteKind::UpdateOrInsert => $query->updateOrInsert(self::attributes($write->conditions), $write->values()),
            WriteKind::UpdateJoined => $this->joinParent($query, $write)->update(array_combine(
                array_map(static fn (string $column): string => "{$write->table}.{$column}", array_keys($write->values())),
                array_values($write->values()),
            )),
            WriteKind::DeleteWhereParentExists => $query->whereExists(function (Builder $exists) use ($write): void {
                $foreignKey = $this->release->schema->foreignKey((string) $write->foreignKey);
                $exists->select(DB::raw(1))->from("{$foreignKey->parent} as parents")->where('parents.label', $write->label);
                foreach ($foreignKey->childColumns as $index => $column) {
                    $exists->whereColumn("parents.{$foreignKey->parentColumns[$index]}", "{$write->table}.{$column}");
                }
            })->delete(),
            WriteKind::Statements => throw new \LogicException('Statements are raw'),
        };
    }

    /**
     * Copies the label of the selected rows, with their amount when the table has one, into the
     * audit table.
     */
    private function insertUsing(Write $write, Table $table): int
    {
        $source = DB::table($write->table)->select('label', $table->hasColumn('amount') ? 'amount' : DB::raw('null'));
        $this->applyConditions($source, $write->conditions, $write->or, null, false);

        return DB::table(SchemaGenerator::AUDIT)->insertUsing(['note', 'ref'], $source);
    }

    private function joinParent(BuilderContract $query, Write $write): BuilderContract
    {
        $foreignKey = $this->release->schema->foreignKey((string) $write->foreignKey);

        return $query->join("{$foreignKey->parent} as joined_parent", function ($join) use ($foreignKey, $write): void {
            foreach ($foreignKey->childColumns as $index => $column) {
                $join->on("{$write->table}.{$column}", '=', "joined_parent.{$foreignKey->parentColumns[$index]}");
            }
        })->where('joined_parent.label', $write->label);
    }

    /**
     * @param  list<Condition>  $conditions
     * @return array<string, int|string>
     */
    private static function attributes(array $conditions): array
    {
        $attributes = [];
        foreach ($conditions as $condition) {
            $attributes[$condition->column] = $condition->values[0];
        }

        return $attributes;
    }

    private function runRawWrite(Write $write): mixed
    {
        if ($write->kind === WriteKind::Statements) {
            return DB::unprepared($this->statements($write));
        }

        $sql = new RawSql($write->style ?? RawStyle::plain(), DB::connection()->getTablePrefix());
        $table = $this->release->schema->table($write->table);
        $returning = $sql->returning($sql->columns($table->primaryKey));
        [$where, $bindings] = $sql->conditions($write->conditions, $write->or, null);
        $where = $where === '' ? '' : " where {$where}";
        $run = static fn (string $statement, array $values, Closure $plain): mixed => $returning === '' ? $plain($sql->finish($statement), $values) : DB::select($sql->finish($statement.$returning), $values);

        return match ($write->kind) {
            WriteKind::Insert => array_map(
                fn (array $row): mixed => $run("insert into {$sql->table($write->table)} ({$sql->columns(array_keys($row))}) values (".self::placeholders($row).')', array_values($row), DB::insert(...)),
                $write->rows,
            ),
            WriteKind::Update => $run("update {$sql->table($write->table)} set {$this->assignments($sql, $write->values(), null)}{$where}", [...array_values($write->values()), ...$bindings], DB::update(...)),
            WriteKind::Delete => $run("delete from {$sql->table($write->table)}{$where}", $bindings, DB::delete(...)),
            WriteKind::Upsert => DB::insert($sql->finish($this->upsert($sql, $write, $table)), array_merge(...array_map(array_values(...), $write->rows))),
            WriteKind::Replace => DB::insert(
                $sql->finish(($this->driver() === 'sqlite' && $write->style?->quoting->value !== 'none' ? 'insert or replace into ' : 'replace into ')
                    ."{$sql->table($write->table)} ({$sql->columns(array_keys($write->values()))}) values ".self::rowsPlaceholders($write->rows)),
                array_merge(...array_map(array_values(...), $write->rows)),
            ),
            WriteKind::UpdateJoined => DB::update(...$this->updateJoined($sql, $write)),
            WriteKind::DeleteWhereParentExists => DB::delete(...$this->deleteJoined($sql, $write)),
            WriteKind::CteDelete => DB::delete(
                $sql->finish("with doomed as (select {$sql->column($table->primaryKey[0])} from {$sql->table($write->table)}{$where}) delete from {$sql->table($write->table)}"
                    ." where {$sql->column($table->primaryKey[0])} in (select {$sql->column($table->primaryKey[0])} from doomed)"),
                $bindings,
            ),
            WriteKind::Merge => DB::affectingStatement(...$this->merge($sql, $write, $table)),
            default => throw new \LogicException("No raw form of {$write->kind->value}"),
        };
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * @param  array<string, int|string|null>  $values
     */
    private function assignments(RawSql $sql, array $values, ?string $qualifier): string
    {
        return implode(', ', array_map(static fn (string $column): string => "{$sql->column($column, $qualifier)} = ?", array_keys($values)));
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function placeholders(array $row): string
    {
        return implode(', ', array_fill(0, count($row), '?'));
    }

    /**
     * @param  list<array<string, int|string|null>>  $rows
     */
    private static function rowsPlaceholders(array $rows): string
    {
        return implode(', ', array_map(static fn (array $row): string => '('.self::placeholders($row).')', $rows));
    }

    /**
     * An insert updating the label and amount of the rows whose key exists, in the database's
     * own syntax, or leaving them as they are when it gives neither.
     */
    private function upsert(RawSql $sql, Write $write, Table $table): string
    {
        $updated = array_values(array_intersect(['label', 'amount'], array_keys($write->values())));
        $insert = "insert into {$sql->table($write->table)} ({$sql->columns(array_keys($write->values()))}) values ".self::rowsPlaceholders($write->rows);

        if (in_array($this->driver(), ['mysql', 'mariadb'], true)) {
            $assignments = $updated === []
                ? "{$sql->column($table->primaryKey[0])} = {$sql->column($table->primaryKey[0])}"
                : implode(', ', array_map(static fn (string $column): string => "{$sql->column($column)} = values({$sql->column($column)})", $updated));

            return "{$insert} on duplicate key update {$assignments}";
        }

        return $updated === []
            ? "{$insert} on conflict ({$sql->columns($table->primaryKey)}) do nothing"
            : "{$insert} on conflict ({$sql->columns($table->primaryKey)}) do update set ".implode(', ', array_map(static fn (string $column): string => "{$sql->column($column)} = excluded.{$sql->column($column)}", $updated));
    }

    /**
     * An update of the rows whose parent has the label, joined to it as the database spells it.
     *
     * @return array{string, list<int|string|null>}
     */
    private function updateJoined(RawSql $sql, Write $write): array
    {
        $foreignKey = $this->release->schema->foreignKey((string) $write->foreignKey);
        [$child, $parent] = [$sql->alias('c'), $sql->alias('p')];
        $on = self::joinCondition($sql, $foreignKey, $child, $parent);
        [$where, $bindings] = $sql->conditions($write->conditions, $write->or, $child);
        $where = $where === '' ? '' : " and {$where}";
        $values = array_values($write->values());

        if (in_array($this->driver(), ['mysql', 'mariadb'], true)) {
            return [$sql->finish("update {$sql->table($write->table)} as {$child} join {$sql->table($foreignKey->parent)} as {$parent} on {$on}"
                ." set {$this->assignments($sql, $write->values(), $child)} where {$sql->column('label', $parent)} = ?{$where}"), [...$values, $write->label, ...$bindings]];
        }

        return [$sql->finish("update {$sql->table($write->table)} as {$child} set {$this->assignments($sql, $write->values(), null)}"
            ." from {$sql->table($foreignKey->parent)} as {$parent} where {$on} and {$sql->column('label', $parent)} = ?{$where}"), [...$values, $write->label, ...$bindings]];
    }

    /**
     * A delete of the rows whose parent has the label, as the database spells a delete through
     * another table.
     *
     * @return array{string, list<int|string|null>}
     */
    private function deleteJoined(RawSql $sql, Write $write): array
    {
        $foreignKey = $this->release->schema->foreignKey((string) $write->foreignKey);
        $parent = $sql->alias('p');

        if ($this->driver() === 'sqlite') {
            $table = $sql->table($write->table);
            [$where, $bindings] = $sql->conditions($write->conditions, $write->or, $table);

            return [$sql->finish("delete from {$table} where exists (select 1 from {$sql->table($foreignKey->parent)} as {$parent} where "
                .self::joinCondition($sql, $foreignKey, $table, $parent)." and {$sql->column('label', $parent)} = ?)".($where === '' ? '' : " and {$where}")), [$write->label, ...$bindings]];
        }

        $child = $sql->alias('c');
        [$where, $bindings] = $sql->conditions($write->conditions, $write->or, $child);
        $where = $where === '' ? '' : " and {$where}";
        $on = self::joinCondition($sql, $foreignKey, $child, $parent);

        return [$sql->finish(in_array($this->driver(), ['mysql', 'mariadb'], true)
            ? "delete {$child} from {$sql->table($write->table)} as {$child} join {$sql->table($foreignKey->parent)} as {$parent} on {$on} where {$sql->column('label', $parent)} = ?{$where}"
            : "delete from {$sql->table($write->table)} as {$child} using {$sql->table($foreignKey->parent)} as {$parent} where {$on} and {$sql->column('label', $parent)} = ?{$where}"), [$write->label, ...$bindings]];
    }

    private static function joinCondition(RawSql $sql, ForeignKey $foreignKey, string $child, string $parent): string
    {
        return implode(' and ', array_map(
            static fn (string $column, string $parentColumn): string => "{$sql->column($column, $child)} = {$sql->column($parentColumn, $parent)}",
            $foreignKey->childColumns,
            $foreignKey->parentColumns,
        ));
    }

    /**
     * A PostgreSQL `MERGE` of the row: updating its label and amount when its key exists,
     * inserting it otherwise. The values are cast to the columns' types, which a `VALUES` list
     * does not know.
     *
     * @return array{string, list<int|string|null>}
     */
    private function merge(RawSql $sql, Write $write, Table $table): array
    {
        $row = $write->values();
        $columns = array_keys($row);
        $key = $table->primaryKey[0];
        [$target, $source] = [$sql->alias('t'), $sql->alias('s')];
        $casts = implode(', ', array_map(static fn (string $column): string => 'cast(? as '.match ($table->column($column)->kind) {
            ValueKind::Integer => 'bigint',
            ValueKind::Text => 'varchar',
            ValueKind::Timestamp => 'timestamp',
        }.')', $columns));
        $updated = array_values(array_intersect(['label', 'amount'], $columns));
        $matched = $updated === []
            ? 'do nothing'
            : 'update set '.implode(', ', array_map(static fn (string $column): string => "{$sql->column($column)} = {$sql->column($column, $source)}", $updated));

        return [$sql->finish("merge into {$sql->table($write->table)} as {$target} using (values ({$casts})) as {$source} ({$sql->columns($columns)})"
            ." on {$sql->column($key, $target)} = {$sql->column($key, $source)} when matched then {$matched}"
            ." when not matched then insert ({$sql->columns($columns)}) values ({$sql->columns($columns, $source)})"), array_values($row)];
    }

    /**
     * An update of the selected rows and one of the audit table, as one string of SQL with the
     * values written into it, as `DB::unprepared()` takes it.
     */
    private function statements(Write $write): string
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $update = DB::table($write->table);
        $this->applyConditions($update, $write->conditions, $write->or, null, false);
        $first = $grammar->substituteBindingsIntoRawSql(
            $grammar->compileUpdate($update, $write->values()),
            $connection->prepareBindings($grammar->prepareBindingsForUpdate($update->bindings, $write->values())),
        );
        $audit = $grammar->wrapTable(SchemaGenerator::AUDIT);

        return "{$first}; update {$audit} set {$grammar->wrap('note')} = 'statements' where {$grammar->wrap('id')} = 1";
    }

    private function modelQuery(string $table, bool $cached): EloquentBuilder
    {
        /** @var class-string<Model> $class */
        $class = $this->release->model($table)->className($this->release->number);
        $query = $class::query();

        return $cached ? $query : $query->withoutCache();
    }

    private static function model(mixed $model): ?Model
    {
        return $model instanceof Model ? $model : null;
    }

    /**
     * Model queries use `whereKey()` for conditions on the column the model calls its key.
     *
     * @param  list<Condition>  $conditions
     */
    private function applyConditions(BuilderContract $query, array $conditions, bool $or, ?string $qualifier, bool $byModelKey): void
    {
        $keyName = $byModelKey ? $this->release->model((string) $qualifier)->keyName : null;

        foreach ($conditions as $index => $condition) {
            $boolean = $or && $index > 0 ? 'or' : 'and';
            $column = $qualifier === null || $byModelKey ? $condition->column : "{$qualifier}.{$condition->column}";

            if ($condition->column === $keyName && $boolean === 'and' && in_array($condition->operator, [Operator::Equals, Operator::In], true)) {
                \assert($query instanceof EloquentBuilder);
                $query->whereKey($condition->operator === Operator::Equals ? $condition->values[0] : $condition->values);

                continue;
            }

            match ($condition->operator) {
                Operator::Equals => $query->where($column, '=', $condition->values[0], $boolean),
                Operator::GreaterThan => $query->where($column, '>', $condition->values[0], $boolean),
                Operator::In => $query->whereIn($column, $condition->values, $boolean),
                Operator::IsNull => $query->whereNull($column, $boolean),
            };
        }
    }

    /**
     * @param  list<Condition>  $conditions
     */
    private function applyRawConditions(Builder $query, array $conditions, bool $or, ?string $qualifier): void
    {
        [$sql, $bindings] = $this->conditionSql($conditions, $or, $qualifier);

        if ($sql !== '') {
            $query->whereRaw($sql, $bindings);
        }
    }

    /**
     * @param  list<Condition>  $conditions
     * @return array{string, list<int|string>}
     */
    private function conditionSql(array $conditions, bool $or, ?string $qualifier): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $parts = [];
        $bindings = [];

        foreach ($conditions as $condition) {
            $column = $grammar->wrap($qualifier === null ? $condition->column : "{$qualifier}.{$condition->column}");
            $parts[] = match ($condition->operator) {
                Operator::Equals => "{$column} = ?",
                Operator::GreaterThan => "{$column} > ?",
                Operator::In => "{$column} in (".implode(', ', array_fill(0, count($condition->values), '?')).')',
                Operator::IsNull => "{$column} is null",
            };
            array_push($bindings, ...$condition->values);
        }

        return [implode($or ? ' or ' : ' and ', $parts), $bindings];
    }

    /**
     * Rows as arrays, with every list of them, eager loaded relations included, in a canonical
     * order, since a read without `ORDER BY` may return its rows in another order each time.
     *
     * @param  array<mixed>  $rows
     * @return list<mixed>
     */
    private static function canonical(array $rows): array
    {
        $normalized = self::normalize(json_decode((string) json_encode(array_values($rows)), true));

        return is_array($normalized) ? array_values($normalized) : [];
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalize(...), $value);

        if (array_is_list($value)) {
            usort($value, static fn (mixed $a, mixed $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));
        }

        return $value;
    }
}
