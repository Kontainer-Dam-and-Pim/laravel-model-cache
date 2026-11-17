<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * The public identifier a row with a key value has: `k5` or `K5` for 5, and a text key itself.
 * Rows keep it, so reads by the identifier and writes by the key meet the same rows, as they do in
 * applications, and distinct keys never share one.
 */
final class Identifier
{
    public static function of(int|string $key, ValueKind $kind, bool $upperCase = false): string
    {
        return $kind === ValueKind::Integer ? ($upperCase ? 'K' : 'k').(int) $key : (string) $key;
    }

    /**
     * The key value of a row with the identifier, for a key of the kind.
     */
    public static function keyOf(string $identifier, ValueKind $kind): int|string
    {
        return $kind === ValueKind::Integer ? (int) ltrim($identifier, 'kK') : $identifier;
    }
}
