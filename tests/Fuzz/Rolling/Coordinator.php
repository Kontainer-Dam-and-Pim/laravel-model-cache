<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Connectivity;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Disturbance;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\DisturbStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Lifecycle;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\LifecycleStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\MigrateStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\PodStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Step;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\PodProcess;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaWriter;

/**
 * Runs a plan from an empty database and an empty Redis: the PHPUnit process is the migration job
 * and the operator, the pods are processes of their releases. Steps the deploy no longer allows,
 * as when shrinking removed the step opening a transaction, are skipped. A read whose cached
 * outcome differs from the database's fails the run.
 */
final class Coordinator
{
    private const int HISTORY_LENGTH = 80;

    /** @var array<string, PodProcess> */
    private array $pods = [];

    /** @var list<string> */
    private array $history = [];

    private RunStatistics $statistics;

    /** @var resource|null */
    private $trace = null;

    /**
     * @param  array<string, mixed>  $config  the configuration every pod boots with, before its release's cache settings
     * @param  string|null  $traceFile  where to write every step and its outcome, for following a run
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly array $config,
        private readonly ?string $traceFile = null,
    ) {}

    /**
     * Runs the steps one at a time, comparing every read with the database.
     *
     * @throws StaleRead
     */
    public function run(): RunStatistics
    {
        return $this->within(function (SchemaWriter $writer): void {
            $state = new DeployState;

            foreach ($this->plan->steps as $index => $step) {
                if (! $state->allows($step)) {
                    $this->log($index, $step, 'skipped, the deploy no longer allows it');
                    $this->statistics->skippedSteps++;

                    continue;
                }

                $this->execute($index, $step, $state, $writer);
                $state = $state->after($step);
            }
        });
    }

    /**
     * Runs the reads and writes between two other steps on all pods at once, each pod its own in
     * order, so they race as pods serving requests do. Then every pod commits what it left open
     * and reads again what it read, which must match the database once writes have settled.
     *
     * @throws StaleRead
     */
    public function runConcurrently(): RunStatistics
    {
        return $this->within(function (SchemaWriter $writer): void {
            $state = new DeployState;
            $segment = [];

            foreach ($this->plan->steps as $index => $step) {
                if ($step instanceof PodStep) {
                    if (isset($this->pods[$step->pod])) {
                        $segment[$step->pod][$index] = $step;
                    }

                    continue;
                }

                $this->runSegment($segment);
                $segment = [];
                $state = $state->settled();

                if (! $state->allows($step)) {
                    $this->log($index, $step, 'skipped, the deploy no longer allows it');
                    $this->statistics->skippedSteps++;

                    continue;
                }

                $this->execute($index, $step, $state, $writer);
                $state = $state->after($step);
            }

            $this->runSegment($segment);
        });
    }

    /**
     * @param  array<string, array<int, PodStep>>  $segment  pod => its steps, by index
     */
    private function runSegment(array $segment): void
    {
        foreach ($segment as $pod => $steps) {
            $this->pods[$pod]->dispatch(['batch' => array_values(array_map(fn (PodStep $step): array => self::message($step), $steps))]);
        }

        foreach ($segment as $pod => $steps) {
            $outcomes = $this->pods[$pod]->receive();

            foreach (array_keys($steps) as $position => $index) {
                $this->record($index, $steps[$index], $outcomes[$position], false);
            }
        }

        foreach ($this->pods as $pod) {
            $pod->send(['settle' => true]);
        }

        foreach ($segment as $pod => $steps) {
            $reads = [];
            foreach ($steps as $index => $step) {
                if ($step->action instanceof Read) {
                    $reads[$step->action->describe()] = [$index, $step];
                }
            }

            foreach ($reads as [$index, $step]) {
                $this->podStep($index, $step);
            }
        }
    }

    /**
     * Prepares an empty database with the plan's first schema and an empty Redis, runs the steps,
     * and stops the pods, killing them when a step fails.
     *
     * @param  \Closure(SchemaWriter): void  $steps
     */
    private function within(\Closure $steps): RunStatistics
    {
        $this->statistics = new RunStatistics;
        $this->trace = $this->traceFile === null ? null : (fopen($this->traceFile, 'w') ?: throw new \RuntimeException("Cannot write the trace to {$this->traceFile}"));
        $writer = new SchemaWriter(DB::connection());
        $writer->dropEverything();
        RedisFacade::connection((string) config('model-cache.redis_connection'))->flushdb();
        $writer->migrate(new Schema([]), $this->plan->schemas[0]);

        try {
            $steps($writer);
        } catch (\Throwable $exception) {
            foreach ($this->pods as $pod) {
                $pod->kill();
            }
            $this->pods = [];
            $this->closeTrace();

            throw $exception;
        }

        $this->closeTrace();

        foreach ($this->pods as $pod) {
            $pod->stop();
        }
        $this->pods = [];

        return $this->statistics;
    }

    private function execute(int $index, Step $step, DeployState $state, SchemaWriter $writer): void
    {
        match (true) {
            $step instanceof PodStep => $this->podStep($index, $step),
            $step instanceof LifecycleStep => $this->lifecycle($index, $step, $state->redisDown),
            $step instanceof MigrateStep => $this->migrate($index, $step, $state, $writer),
            $step instanceof DisturbStep => $this->disturb($index, $step),
        };
    }

    private function podStep(int $index, PodStep $step): void
    {
        $this->record($index, $step, $this->pods[$step->pod]->send(self::message($step)), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(PodStep $step): array
    {
        return match (true) {
            $step->action instanceof Read => ['read' => $step->action->toArray()],
            $step->action instanceof Write => ['write' => $step->action->toArray()],
            $step->action instanceof TransactionAction => ['transaction' => $step->action->value],
        };
    }

    /**
     * Logs and counts the outcome of a pod's step, and fails when it is a compared read whose
     * cached outcome differs from the database's.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function record(int $index, PodStep $step, array $outcome, bool $compare): void
    {
        if (! $step->action instanceof Read) {
            $this->log($index, $step, isset($outcome['error']) ? "rejected: {$outcome['message']}" : 'ok');

            if ($step->action instanceof Write) {
                $this->statistics->writes++;

                if (isset($outcome['error'])) {
                    $this->statistics->rejectedWrites++;
                    $this->statistics->rejections[$outcome['error']] = ($this->statistics->rejections[$outcome['error']] ?? 0) + 1;
                }
            }

            return;
        }

        $this->log($index, $step, ($compare ? '' : 'racing: ').(json_encode($outcome['database']) ?: ''));

        if (! $compare) {
            return;
        }

        $pod = $this->pods[$step->pod];
        $this->statistics->reads++;
        $this->statistics->rejectedReads += isset($outcome['database']['error']) ? 1 : 0;
        $this->statistics->releasesRead[$pod->release] = true;

        if ($outcome['cached'] != $outcome['database']) {
            throw new StaleRead(sprintf(
                "Pod %s of release %d read a cached result that differs from the database at step %d: %s\nCached: %s\nDatabase: %s\nSeed: %d\nLast steps:\n  %s",
                $step->pod,
                $pod->release,
                $index,
                $step->describe(),
                json_encode($outcome['cached']),
                json_encode($outcome['database']),
                $this->plan->seed,
                implode("\n  ", $this->history),
            ), $step->action->describe());
        }
    }

    /**
     * A pod starting while Redis is down starts without it.
     */
    private function lifecycle(int $index, LifecycleStep $step, bool $redisDown): void
    {
        $this->log($index, $step, 'ok');
        $previous = $this->pods[$step->pod] ?? null;
        $release = $this->plan->release($previous?->release ?? $step->release);
        $secondConnection = $previous?->secondConnection ?? $step->secondConnection;

        if ($previous !== null) {
            $previous->stop();
            unset($this->pods[$step->pod]);
        }

        if ($step->lifecycle === Lifecycle::Stop) {
            return;
        }

        $default = (string) $this->config['database.default'];
        $this->pods[$step->pod] = PodProcess::start($step->pod, $release, $secondConnection, [
            ...$this->config,
            'database.default' => $secondConnection ? self::secondConnection($default) : $default,
            'database.connections.'.self::secondConnection($default) => $this->config["database.connections.{$default}"],
            'model-cache' => [...$this->config['model-cache'], ...$release->config],
        ]);

        if ($redisDown) {
            $this->pods[$step->pod]->send(['connectivity' => Connectivity::Lost->value]);
        }
    }

    /**
     * The name of a second connection to the same database as the one named.
     */
    private static function secondConnection(string $connection): string
    {
        return "{$connection}_second";
    }

    private function migrate(int $index, MigrateStep $step, DeployState $state, SchemaWriter $writer): void
    {
        $writer->migrate($this->plan->schemas[$state->schema], $this->plan->schemas[$step->schema]);
        $this->log($index, $step, 'ok');
        $this->statistics->migrations++;
    }

    private function disturb(int $index, DisturbStep $step): void
    {
        $connection = RedisFacade::connection((string) config('model-cache.redis_connection'));

        if ($step->disturbance === Disturbance::Outage || $step->disturbance === Disturbance::Recovery) {
            foreach ($this->pods as $pod) {
                $pod->send(['connectivity' => ($step->disturbance === Disturbance::Outage ? Connectivity::Lost : Connectivity::Restored)->value]);
            }
        } elseif ($step->disturbance === Disturbance::Flush) {
            Services::cache()->flush();
        } elseif ($step->disturbance === Disturbance::Empty) {
            $connection->flushdb();
        } else {
            $keys = $connection->keys(Services::redis()->prefix('*'));
            sort($keys, SORT_STRING);
            $dice = new Dice($step->seed);

            foreach ($keys as $key) {
                if ($dice->chance(33)) {
                    $connection->del((string) $key);
                }
            }
        }

        $this->log($index, $step, 'ok');
    }

    private function closeTrace(): void
    {
        if ($this->trace !== null) {
            fclose($this->trace);
            $this->trace = null;
        }
    }

    private function log(int $index, Step $step, string $outcome): void
    {
        $this->history[] = sprintf('#%d %s => %s', $index, $step->describe(), mb_strimwidth($outcome, 0, 300, '…'));

        if ($this->trace !== null) {
            fwrite($this->trace, sprintf("#%d %s => %s\n", $index, $step->describe(), $outcome));
        }

        if (count($this->history) > self::HISTORY_LENGTH) {
            array_shift($this->history);
        }
    }
}
