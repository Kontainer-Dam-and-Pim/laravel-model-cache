<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Plan;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\PlanGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\RunsDeploys;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\StaleRead;
use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * Pods of the releases of rolling deploys serve their reads and writes at the same time, racing
 * for rows and for the cache, between the migrations, restarts and Redis outages of the deploys.
 * Whenever they pause, every read a pod made must match the database again. Races make runs
 * differ, so a failing plan is written to `build/fuzz` without shrinking; `MODEL_CACHE_FUZZ_PLAN`
 * replays it, races and all.
 */
final class ConcurrentDeployTest extends TestCase
{
    use RunsDeploys;

    private const int DEFAULT_RUNS = 2;

    private const int STEPS_PER_PHASE = 12;

    public function testEveryPodReadsWhatTheDatabaseHoldsOnceConcurrentPodsPause(): void
    {
        $replay = getenv('MODEL_CACHE_FUZZ_PLAN');

        if ($replay !== false && $replay !== '') {
            $this->runConcurrently(self::readPlan($replay));

            return;
        }

        $seed = FuzzEnvironment::seed();
        $driver = Driver::of(DB::connection()->getDriverName());

        for ($run = 0; $run < FuzzEnvironment::runs(self::DEFAULT_RUNS); $run++) {
            $this->runConcurrently((new PlanGenerator($seed + $run, $driver, self::STEPS_PER_PHASE))->generate());
        }
    }

    private function runConcurrently(Plan $plan): void
    {
        try {
            $statistics = $this->coordinator($plan)->runConcurrently();
        } catch (StaleRead $failure) {
            $this->fail(sprintf("%s\n\nReplay with MODEL_CACHE_FUZZ_PLAN=%s", $failure->getMessage(), $this->writeFailingPlan($plan, 'concurrent')));
        }

        $this->assertGreaterThan(0, $statistics->reads, "seed {$plan->seed}: {$statistics->describe()}");
    }
}
