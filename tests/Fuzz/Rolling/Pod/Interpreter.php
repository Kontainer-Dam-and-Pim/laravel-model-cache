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
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\WriteKind;
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
     * Inside a transaction each run of the read has a savepoint of its own, so one rejected on
     * PostgreSQL does not abort the transaction for the other.
     *
     * @return array{cached: array<string, mixed>, database: array<string, mixed>}
     */
    public function read(Read $read): array
    {
        $isolated = static fn (Closure $run): Closure => DB::transactionLevel() > 0 ? static fn (): mixed => DB::transaction($run) : $run;

        return [
            'cached' => self::outcome($isolated(fn (): mixed => $this->runRead($read, true))),
            'database' => self::outcome($isolated(fn (): mixed => $this->runRead($read, false))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function write(Write $write): array
    {
        return self::outcome(fn (): mixed => $this->runWrite($write));
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
            $query = $this->modelQuery($read->relation, $cached);
            $this->applyConditions($query, $read->conditions, $read->or, $read->relation, true);

            if ($read->with !== null) {
                $query->with([$read->with => static fn (Relation $relation) => $cached ? $relation : $relation->withoutCache()]);
            }

            return $read->count ? $query->count() : self::canonical($query->get()->toArray());
        }

        $query = DB::table($read->relation);
        if (! $cached) {
            $query->withoutCache();
        }

        if ($read->join !== null) {
            $foreignKey = $this->release->schema->foreignKey($read->join);
            $query->join("{$foreignKey->parent} as joined_parent", function ($join) use ($foreignKey, $read): void {
                foreach ($foreignKey->childColumns as $index => $column) {
                    $join->on("{$read->relation}.{$column}", '=', "joined_parent.{$foreignKey->parentColumns[$index]}");
                }
            })->select("{$read->relation}.*", 'joined_parent.label as parent_label');
        }

        $read->api === Api::Raw
            ? $this->applyRawConditions($query, $read->conditions, $read->or, $read->join === null ? null : $read->relation)
            : $this->applyConditions($query, $read->conditions, $read->or, $read->join === null ? null : $read->relation, false);

        return $read->count ? $query->count() : self::canonical($query->get()->all());
    }

    private function runWrite(Write $write): mixed
    {
        $table = $this->release->schema->table($write->table);

        if ($write->api === Api::Raw) {
            return $this->runRawWrite($write);
        }

        $query = $write->api === Api::Model ? $this->modelQuery($write->table, true) : DB::table($write->table);
        $this->applyConditions($query, $write->conditions, $write->or, $write->table, $write->api === Api::Model);

        return match ($write->kind) {
            WriteKind::Insert => $write->api === Api::Model
                ? array_map(static fn (array $row): mixed => $query->create($row)->getKey(), $write->rows)
                : $query->insert($write->rows),
            WriteKind::InsertOrIgnore => $query->insertOrIgnore($write->rows),
            WriteKind::InsertGetId => $query->insertGetId($write->values()),
            WriteKind::Upsert => $query->upsert($write->rows, $table->primaryKey, array_values(array_intersect(['label', 'amount'], array_keys($write->values())))),
            WriteKind::Update => $query->update($write->values()),
            WriteKind::Increment => $query->increment('amount', (int) $write->values()['amount']),
            WriteKind::Delete => $query->delete(),
            WriteKind::Truncate => $query->truncate(),
            WriteKind::Save => self::model($query->first())?->fill($write->values())->save(),
            WriteKind::DeleteModel => self::model($query->first())?->delete(),
        };
    }

    private function runRawWrite(Write $write): mixed
    {
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable($write->table);
        [$where, $bindings] = $this->conditionSql($write->conditions, $write->or, null);
        $where = $where === '' ? '' : " where {$where}";

        return match ($write->kind) {
            WriteKind::Insert => array_map(
                static fn (array $row): bool => DB::insert(
                    "insert into {$table} (".implode(', ', array_map($grammar->wrap(...), array_keys($row))).') values ('.implode(', ', array_fill(0, count($row), '?')).')',
                    array_values($row),
                ),
                $write->rows,
            ),
            WriteKind::Update => DB::update(
                "update {$table} set ".implode(', ', array_map(static fn (string $column): string => $grammar->wrap($column).' = ?', array_keys($write->values())))."{$where}",
                [...array_values($write->values()), ...$bindings],
            ),
            WriteKind::Delete => DB::delete("delete from {$table}{$where}", $bindings),
            default => throw new \LogicException("No raw form of {$write->kind->value}"),
        };
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
