<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Database;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kontainer\ModelCache\QueryHandler;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Support\AssignedColumns;
use Kontainer\ModelCache\Support\SqlAliasParser;
use Kontainer\ModelCache\Support\Write;
use LogicException;

/**
 * Model Cache query builder for Laravel 12+
 *
 * Extends the base query builder to provide automatic caching and invalidation.
 */
class QueryBuilder extends Builder implements HasCompiledSubQueries
{
    use TracksCompiledSubQueries;

    public const string DEFAULT_PRIMARY_KEY_NAME = 'id';

    private readonly QueryHandler $handler;

    private readonly ?Model $model;

    private bool $cacheBypassed = false;

    final public function __construct(
        Connection $connection,
        QueryHandler $handler,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        ?Model $model = null
    ) {
        parent::__construct($connection, $grammar, $processor);
        $this->handler = $handler;
        $this->model = $model;
    }

    /**
     * Bypass Model Cache for this query (skips read caching and write invalidation).
     */
    public function withoutCache(): static
    {
        $this->cacheBypassed = true;

        return $this;
    }

    public function isCacheBypassed(): bool
    {
        return $this->cacheBypassed;
    }

    /** {@inheritDoc} */
    public function exists()
    {
        if ($this->lock !== null) {
            return parent::exists();
        }

        return $this->clone()->limit(1)->get([new Expression('1 as model_cache_exists')])->isNotEmpty();
    }

    public function getHandler(): QueryHandler
    {
        return $this->handler;
    }

    public function getConnection(): Connection
    {
        return $this->connection instanceof Connection ? $this->connection : throw new LogicException('Model Cache query builders run on database connections, got '.get_debug_type($this->connection));
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * The column a delete of the table is narrowed by when its conditions pin it: the model's key
     * for the model's own table, the conventional key otherwise. Any pinned column narrows a
     * delete correctly, since the dependency map lists foreign keys by the column they reference;
     * row tags never use it, they take the key from the schema (see `Tagger`).
     */
    public function primaryKeyOf(string $table): string
    {
        return $this->model !== null && $this->model->getTable() === $table
            ? $this->model->getKeyName()
            : self::DEFAULT_PRIMARY_KEY_NAME;
    }

    private function targetTable(): ?string
    {
        return is_string($this->from) ? SqlAliasParser::stripAlias($this->from) : null;
    }

    /** {@inheritDoc} */
    public function newQuery()
    {
        return new static(
            $this->getConnection(),
            $this->handler,
            $this->grammar,
            $this->processor,
            $this->model
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  ExpressionContract|string  $table
     */
    protected function newJoinClause(Builder $parentQuery, $type, $table)
    {
        return new JoinClause($this, $type, $table);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<mixed>
     */
    protected function runSelect()
    {
        if ($this->lock !== null || $this->cacheBypassed || $this->fetchUsing !== []) {
            return $this->runStatement(fn () => parent::runSelect());
        }

        $this->applyBeforeQueryCallbacks();

        return $this->handler->cacheQuery($this, fn (string $sql): array => $this->runStatement(fn () => $this->connection->select(
            $sql,
            $this->getBindings(),
            ! $this->useWritePdo
        )));
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values)
    {
        $result = $this->runStatement(fn () => parent::insert($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, AssignedColumns::inserted($this->targetTable(), $values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  list<string>  $columns
     */
    public function insertUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), [], AssignedColumns::unknown());

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  list<string>  $columns
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertOrIgnoreUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), [], AssignedColumns::unknown());

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values)
    {
        $result = $this->runStatement(fn () => parent::updateFrom($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(array_keys($values))->affecting($result), $values, AssignedColumns::updated($this->targetTable(), $values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = $this->runStatement(fn () => parent::insertGetId($values, $sequence));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, AssignedColumns::inserted($this->targetTable(), $values + [$sequence ?? self::DEFAULT_PRIMARY_KEY_NAME => $id]));

        return $id;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        $count = $this->runStatement(fn () => parent::update($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(array_keys($values))->affecting($count), $values, AssignedColumns::updated($this->targetTable(), $values));

        return $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        $result = $this->runStatement(fn () => parent::upsert($values, $uniqueBy, $update));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(self::upsertedColumns($values, $update))->affecting($result), $values, AssignedColumns::unknown());

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnore($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, AssignedColumns::inserted($this->targetTable(), $values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     * @return Collection<int, \stdClass>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnoreReturning($values, $returning, $uniqueBy));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, AssignedColumns::inserted($this->targetTable(), $values));

        return $result;
    }

    /** {@inheritDoc} */
    public function delete($id = null)
    {
        $count = $this->runStatement(fn () => parent::delete($id));
        $this->invalidate(Reflector::QUERY_TYPE_DELETE, Write::delete()->affecting($count), [], AssignedColumns::none());

        return $count;
    }

    /** {@inheritDoc} */
    public function truncate()
    {
        $this->runStatement(fn () => parent::truncate());
        $this->invalidate(Reflector::QUERY_TYPE_TRUNCATE, Write::truncate(), [], AssignedColumns::unknown());
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $statement
     * @return TResult
     */
    private function runStatement(Closure $statement): mixed
    {
        return $this->connection instanceof RunsBuilderStatements
            ? $this->connection->runBuilderStatement($statement)
            : $statement();
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function invalidate(string $queryType, Write $write, array $values, AssignedColumns $assigned): void
    {
        $this->handler->invalidateQuery($this, $queryType, $write, $values, $assigned);
    }

    /**
     * The columns an upsert assigns to existing rows, resolved as `Builder::upsert()` does.
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int|string, mixed>|null  $update
     * @return list<int|string>
     */
    private static function upsertedColumns(array $values, ?array $update): array
    {
        if ($update === null) {
            $first = reset($values);

            return array_keys(is_array($first) ? $first : $values);
        }

        $columns = [];
        foreach ($update as $key => $value) {
            $columns[] = is_int($key) ? (is_string($value) ? $value : throw new InvalidArgumentException('Upsert update columns must be names, got '.get_debug_type($value))) : $key;
        }

        return $columns;
    }
}
