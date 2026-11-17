<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Support;

/**
 * How a fuzz run is tuned from the environment: `MODEL_CACHE_FUZZ_SEED` fixes the seed to
 * reproduce a run, `MODEL_CACHE_FUZZ_ITERATIONS` sets how many operations a workload runs, and
 * `MODEL_CACHE_FUZZ_RUNS` how many generated schemas or deploys a test runs, from the seed on.
 */
final class FuzzEnvironment
{
    public static function seed(): int
    {
        $seed = getenv('MODEL_CACHE_FUZZ_SEED');

        return $seed === false || $seed === '' ? random_int(1, PHP_INT_MAX >> 1) : (int) $seed;
    }

    public static function iterations(int $default): int
    {
        return self::positive('MODEL_CACHE_FUZZ_ITERATIONS', $default);
    }

    public static function runs(int $default): int
    {
        return self::positive('MODEL_CACHE_FUZZ_RUNS', $default);
    }

    private static function positive(string $name, int $default): int
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : max(1, (int) $value);
    }
}
