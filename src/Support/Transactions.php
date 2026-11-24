<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Kontainer\ModelCache\Database\RunsBuilderStatements;
use PDO;

final class Transactions
{
    /**
     * Whether the connection is inside a transaction. Where the driver reports the server's
     * transaction state, that state decides: the server may have ended a transaction Laravel
     * still counts as open, as MySQL and MariaDB roll back the whole transaction on a deadlock
     * while Laravel only leaves one level, and a statement that implicitly commits ends it too.
     * Writes then commit as they run, so holding their invalidations for a commit Laravel may
     * never reach would leave the entries they change reachable. Other drivers report only
     * what PDO itself began, so a transaction Laravel opened or a raw `START TRANSACTION` the
     * connection noted counts as well. A connection that has not connected yet has none, and
     * asking never connects it.
     */
    public static function isOpen(ConnectionInterface $connection): bool
    {
        $pdo = $connection instanceof Connection ? $connection->getRawPdo() : null;

        if (! $pdo instanceof PDO) {
            return $connection->transactionLevel() > 0;
        }

        if (self::reportsServerState($pdo)) {
            return $pdo->inTransaction();
        }

        return $connection->transactionLevel() > 0
            || $pdo->inTransaction()
            || ($connection instanceof RunsBuilderStatements && $connection->inRawTransaction());
    }

    private static function reportsServerState(PDO $pdo): bool
    {
        return match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql', 'pgsql' => true,
            'sqlite' => PHP_VERSION_ID >= 80400,
            default => false,
        };
    }
}
