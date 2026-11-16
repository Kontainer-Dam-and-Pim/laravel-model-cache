<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Database;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause as BaseJoinClause;
use Kontainer\ModelCache\QueryHandler;

/**
 * Model Cache-aware join clause.
 *
 * Extends Laravel's JoinClause to properly instantiate QueryBuilder instances
 * with the required QueryHandler when creating nested query builders.
 */
class JoinClause extends BaseJoinClause implements HasCompiledSubQueries
{
    use TracksCompiledSubQueries;

    /** @var class-string<QueryBuilder> */
    private readonly string $parentBuilderClass;

    private readonly Connection $parentDatabaseConnection;

    private readonly QueryHandler $parentHandler;

    private readonly ?Model $parentModel;

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  ExpressionContract|string  $table
     */
    public function __construct(QueryBuilder $parentQuery, $type, $table)
    {
        $this->parentBuilderClass = $parentQuery::class;
        $this->parentDatabaseConnection = $parentQuery->getConnection();
        $this->parentHandler = $parentQuery->getHandler();
        $this->parentModel = $parentQuery->getModel();

        parent::__construct($parentQuery, $type, $table);
    }

    /** {@inheritDoc} */
    protected function newParentQuery()
    {
        return new $this->parentBuilderClass(
            $this->parentDatabaseConnection,
            $this->parentHandler,
            $this->parentGrammar,
            $this->parentProcessor,
            $this->parentModel
        );
    }
}
