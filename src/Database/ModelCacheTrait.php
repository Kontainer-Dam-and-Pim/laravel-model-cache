<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Database;

use Kontainer\ModelCache\Services;

/**
 * Integrates Model Cache into Eloquent models.
 *
 * Include this trait on models to route all base queries through the
 * Model Cache-aware query builder, enabling transparent caching and
 * invalidation.
 */
trait ModelCacheTrait
{
    /** {@inheritDoc} */
    protected function newBaseQueryBuilder()
    {
        // When Model Cache is disabled, use Laravel's default query builder
        if (! (bool) config('model-cache.active', true)) {
            return parent::newBaseQueryBuilder();
        }

        $connection = $this->getConnection();

        $handler = Services::handler();

        return new QueryBuilder(
            $connection,
            $handler,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
            $this
        );
    }
}
