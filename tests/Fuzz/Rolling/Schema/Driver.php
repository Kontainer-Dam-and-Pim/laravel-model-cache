<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * The databases the harness generates schemas for, with what each lets a schema declare.
 */
enum Driver: string
{
    case Sqlite = 'sqlite';
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PgSql = 'pgsql';

    public static function of(string $driverName): self
    {
        return self::from($driverName);
    }

    /**
     * InnoDB parses `SET DEFAULT` but rejects foreign keys using it.
     */
    public function supportsSetDefault(): bool
    {
        return $this === self::Sqlite || $this === self::PgSql;
    }

    public function supportsUpdateOfTriggers(): bool
    {
        return $this === self::Sqlite || $this === self::PgSql;
    }

    public function supportsStatementTriggers(): bool
    {
        return $this === self::PgSql;
    }

    /**
     * MariaDB rejects `ON UPDATE CASCADE` from a `NOT NULL` column to a nullable one, which an
     * update of the parent to NULL could not cascade to.
     */
    public function cascadesUpdatesOnlyIntoNullableColumnsFromNullableOnes(): bool
    {
        return $this === self::MariaDb;
    }

    public function isMySqlFamily(): bool
    {
        return $this === self::MySql || $this === self::MariaDb;
    }
}
