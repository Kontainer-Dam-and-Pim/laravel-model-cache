<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Coordinator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Plan;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\PlanGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\RunStatistics;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Shrinker;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\StaleRead;
use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\TestCase;

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
    private const int DEFAULT_RUNS = 3;

    private const int STEPS_PER_PHASE = 12;

    private const int SHRINK_RUNS = 150;

    private ?string $sqliteFile = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (self::databaseDriver() !== 'sqlite') {
            return;
        }

        $this->sqliteFile = (string) tempnam(sys_get_temp_dir(), 'model-cache-rolling-');
        $app['config']->set('database.connections.testing.database', $this->sqliteFile);
        $app['config']->set('database.connections.testing.journal_mode', 'wal');
        $app['config']->set('database.connections.testing.busy_timeout', 5000);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->sqliteFile !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($this->sqliteFile.$suffix)) {
                    unlink($this->sqliteFile.$suffix);
                }
            }
        }
    }

    public function testEveryPodReadsWhatTheDatabaseHoldsThroughRollingDeploys(): void
    {
        $replay = getenv('MODEL_CACHE_FUZZ_PLAN');

        if ($replay !== false && $replay !== '') {
            $this->runOrShrink(Plan::fromArray(json_decode((string) file_get_contents($replay), true, flags: JSON_THROW_ON_ERROR)));
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
            $file = $this->writeFailingPlan($shrunk);

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
        $default = (string) config('database.default');

        return (new Coordinator($plan, [
            'database.default' => $default,
            "database.connections.{$default}" => config("database.connections.{$default}"),
            'database.redis' => config('database.redis'),
            'model-cache' => config('model-cache'),
        ], getenv('MODEL_CACHE_FUZZ_TRACE') ?: null))->run();
    }

    private function writeFailingPlan(Plan $plan): string
    {
        $directory = dirname(__DIR__, 2).'/build/fuzz';

        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}");
        }

        $file = sprintf('%s/rolling-%s-%d.json', $directory, self::databaseDriver(), $plan->seed);
        $temporary = $file.'.tmp';
        file_put_contents($temporary, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($temporary, $file);

        return $file;
    }
}
