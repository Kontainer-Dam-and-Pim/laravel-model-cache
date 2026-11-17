<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Plan;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\PlanGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\RunsDeploys;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\RunStatistics;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Shrinker;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\StaleRead;
use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Pods of successive releases of an application share one database and one Redis through
 * rolling deploys: migrations run while old pods serve, new pods start next to them, old pods
 * restart and stop, and later migrations drop what the new release no longer uses. Each release
 * has its own schema, models and cache settings. Every read any pod makes must match the
 * database. `MODEL_CACHE_FUZZ_RUNS` sets how many deploys run, from the seed on. A failing run is
 * shrunk to the fewest steps that still fail and written to a file that `MODEL_CACHE_FUZZ_PLAN`
 * replays; `MODEL_CACHE_FUZZ_TRACE` names a file to write every step and its outcome to.
 */
final class RollingDeployFuzzTest extends TestCase
{
    use RunsDeploys;

    private const int DEFAULT_RUNS = 3;

    private const int STEPS_PER_PHASE = 12;

    private const int SHRINK_RUNS = 150;

    public function testEveryPodReadsWhatTheDatabaseHoldsThroughRollingDeploys(): void
    {
        $this->runDeploys();
    }

    #[DefineEnvironment('usePrefixedTables')]
    public function testEveryPodReadsWhatTheDatabaseHoldsThroughRollingDeploysOfPrefixedTables(): void
    {
        $this->runDeploys();
    }

    /**
     * @param  Application  $app
     */
    protected function usePrefixedTables($app): void
    {
        $connection = (string) $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.prefix", 'lc_');
        $app['config']->set("database.connections.{$connection}.prefix_indexes", true);
    }

    private function runDeploys(): void
    {
        $replay = getenv('MODEL_CACHE_FUZZ_PLAN');

        if ($replay !== false && $replay !== '') {
            $this->runOrShrink(self::readPlan($replay));
            $this->addToAssertionCount(1);

            return;
        }

        $seed = FuzzEnvironment::seed();
        $driver = Driver::of(DB::connection()->getDriverName());

        for ($run = 0; $run < FuzzEnvironment::runs(self::DEFAULT_RUNS); $run++) {
            $plan = (new PlanGenerator($seed + $run, $driver, self::STEPS_PER_PHASE))->generate();
            $this->assertExercised($this->runOrShrink($plan), $plan->seed);
        }
    }

    private function runOrShrink(Plan $plan): RunStatistics
    {
        try {
            return $this->runPlan($plan);
        } catch (StaleRead $failure) {
            [$shrunk, $shrunkFailure] = (new Shrinker($this->runPlan(...), self::SHRINK_RUNS))->shrink($plan, $failure);
            $file = $this->writeFailingPlan($shrunk, 'rolling');

            $this->fail(sprintf(
                "%s\n\nShrunk from %d to %d steps; replay with MODEL_CACHE_FUZZ_PLAN=%s",
                $shrunkFailure->getMessage(),
                count($plan->steps),
                count($shrunk->steps),
                $file,
            ));
        }
    }

    /**
     * A generated plan migrates the database, reads through pods of more than one release, and
     * reaches the database with most of its reads and a fair share of its writes.
     */
    private function assertExercised(RunStatistics $statistics, int $seed): void
    {
        $context = "seed {$seed}: {$statistics->describe()}";

        $this->assertGreaterThan(0, $statistics->migrations, $context);
        $this->assertGreaterThan(1, count($statistics->releasesRead), $context);
        $this->assertLessThan(0.5, $statistics->rejectedReads / max(1, $statistics->reads), $context);
        $this->assertLessThan(0.75, $statistics->rejectedWrites / max(1, $statistics->writes), $context);
    }

    private function runPlan(Plan $plan): RunStatistics
    {
        return $this->coordinator($plan)->run();
    }
}
