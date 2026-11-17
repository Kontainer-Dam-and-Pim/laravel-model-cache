<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;

/**
 * How hand-written SQL quotes identifiers.
 */
enum Quoting: string
{
    case None = 'none';
    case DoubleQuotes = 'double';
    case Backticks = 'backticks';
    case Brackets = 'brackets';

    /**
     * @return list<self>
     */
    public static function supportedBy(Driver $driver): array
    {
        return match ($driver) {
            Driver::MySql, Driver::MariaDb => [self::None, self::Backticks],
            Driver::PgSql => [self::None, self::DoubleQuotes],
            Driver::Sqlite => self::cases(),
        };
    }

    public function quote(string $identifier): string
    {
        return match ($this) {
            self::None => $identifier,
            self::DoubleQuotes => '"'.$identifier.'"',
            self::Backticks => '`'.$identifier.'`',
            self::Brackets => '['.$identifier.']',
        };
    }
}
