<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Kontainer\ModelCache\Services;

/**
 * Model Cache-aware MariaDB connection.
 *
 * MariaDB is compatible with Laravel's MySQL driver, so we extend
 * Illuminate's MySqlConnection while providing the caching query builder.
 */
final class MariaDbConnection extends BaseMySqlConnection implements RunsBuilderStatements
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
