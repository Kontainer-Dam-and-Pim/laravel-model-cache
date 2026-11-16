<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Kontainer\ModelCache\Services;

/**
 * Model Cache-aware Postgres connection.
 */
final class PostgresConnection extends BasePostgresConnection implements RunsBuilderStatements
{
    use InvalidatesRawStatements;

    /** {@inheritDoc} */
    public function query()
    {
        if (! (bool) config('model-cache.active', true)) {
            return parent::query();
        }

        $handler = Services::handler();

        return new QueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
