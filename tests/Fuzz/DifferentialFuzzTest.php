<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz;

use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\Fuzz\Support\Workload;
use Kontainer\ModelCache\Tests\TestCase;

final class DifferentialFuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 400;

    private const int SWEEP_INTERVAL = 100;

    public function testCachedReadsAlwaysMatchTheDatabase(): void
    {
        $this->runWorkload(FuzzEnvironment::iterations(self::DEFAULT_ITERATIONS));
    }

    public function testCachedReadsMatchTheDatabaseWithoutRowLevelTags(): void
    {
        config(['model-cache.consider_rows' => false]);

        $this->runWorkload(intdiv(FuzzEnvironment::iterations(self::DEFAULT_ITERATIONS), 2));
    }

    public function testCachedReadsMatchTheDatabaseWhenOnlyModelsAreCached(): void
    {
        config(['model-cache.scope' => 'models']);

        $this->runWorkload(intdiv(FuzzEnvironment::iterations(self::DEFAULT_ITERATIONS), 2));
    }

    public function testCachedReadsMatchTheDatabaseWithoutExpiration(): void
    {
        config(['model-cache.expiration_time' => 0]);
        $this->app->forgetInstance('model-cache.cache');
        $this->app->forgetInstance('model-cache.handler');

        $this->runWorkload(intdiv(FuzzEnvironment::iterations(self::DEFAULT_ITERATIONS), 2));
    }

    private function runWorkload(int $iterations): void
    {
        $workload = new Workload(FuzzEnvironment::seed(), self::databaseDriver());
        $workload->run($iterations, self::SWEEP_INTERVAL);

        $this->assertGreaterThan(0, $workload->comparisons());
        $this->assertSame([], $workload->writesAlwaysRejected(5), 'These writes never succeeded, so the workload does not exercise them');
    }
}
