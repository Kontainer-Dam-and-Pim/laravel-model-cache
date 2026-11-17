<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded choices, so a seed regenerates the same schemas, releases and steps.
 */
final readonly class Dice
{
    private Randomizer $random;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /**
     * True with the given chance in percent.
     */
    public function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  array<array-key, TValue>  $values
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        if ($values === []) {
            throw new LogicException('Cannot pick from nothing');
        }

        $values = array_values($values);

        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    /**
     * Up to $max distinct values, in their original order.
     *
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return list<TValue>
     */
    public function some(array $values, int $max): array
    {
        $count = $this->random->getInt(0, min($max, count($values)));
        $keys = $count === 0 ? [] : (array) $this->random->pickArrayKeys($values, $count);

        return array_values(array_intersect_key($values, array_flip($keys)));
    }

    /**
     * A choice weighted by the given weights.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return TKey
     */
    public function weighted(array $weights): int|string
    {
        $roll = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $choice => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $choice;
            }
        }

        throw new LogicException('Weights must be positive');
    }
}
