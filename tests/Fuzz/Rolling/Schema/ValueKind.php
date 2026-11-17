<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * The kind of value a column holds, with the small domain the harness draws values from so that
 * reads and writes keep meeting the same rows. Text values differ only in case in places, which
 * case-insensitive collations compare as equal.
 */
enum ValueKind: string
{
    case Integer = 'int';
    case Text = 'text';

    private const array INTEGERS = [1, 2, 3, 4, 5, 6];

    private const array TEXTS = ['k1', 'K1', 'k2', 'k3', 'K3', 'k4', 'k5', 'k6'];

    /**
     * @return list<int|string>
     */
    public function domain(): array
    {
        return $this === self::Integer ? self::INTEGERS : self::TEXTS;
    }

    /**
     * Spellings of a value the database compares as equal to it, which the cache must key alike.
     *
     * @return list<int|string>
     */
    public function spellings(int|string $value): array
    {
        return $this === self::Integer ? [$value, (string) $value, sprintf('%02d', $value)] : [$value];
    }
}
