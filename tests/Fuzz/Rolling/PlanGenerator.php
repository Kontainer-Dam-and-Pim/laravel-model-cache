<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\Release;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\ReleaseGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Disturbance;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\DisturbStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Lifecycle;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\LifecycleStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\MigrateStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\PodStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Read;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Step;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use LogicException;

/**
 * Generates the deploys of a run. Two pods of the first release start on the initial schema.
 * Each deploy then migrates the database with additions only while the old pods keep serving,
 * starts pods of the new release next to them, restarts and stops the old pods one by one, and
 * runs the migration removing what the new release no longer uses, usually once the old pods are
 * gone and sometimes before, as a hurried deploy does. A deploy is sometimes rolled back by a pod
 * of the previous release. Throughout, pods read before and after writes, hold transactions, and
 * Redis is flushed, emptied or evicts keys. At the end every pod reads again everything its
 * release read.
 */
final class PlanGenerator
{
    private readonly Dice $dice;

    private readonly OperationGenerator $operations;

    private DeployState $state;

    /** @var list<Step> */
    private array $steps = [];

    /** @var array<int, array<string, Read>> release => reads it made, by their description */
    private array $reads = [];

    private int $pods = 0;

    /** @var list<PodStep> reads around writes of the open transaction, read again once it ends */
    private array $readsAwaitingCommit = [];

    public function __construct(
        private readonly int $seed,
        private readonly Driver $driver,
        private readonly int $stepsPerPhase,
    ) {
        $this->dice = new Dice($seed);
        $this->operations = new OperationGenerator($this->dice);
        $this->state = new DeployState;
    }

    public function generate(): Plan
    {
        $schemaGenerator = new SchemaGenerator($this->dice, $this->driver);
        $releaseGenerator = new ReleaseGenerator($this->dice);
        $schemas = [$schemaGenerator->initial()];
        $releases = [$releaseGenerator->initial($schemas[0])];

        foreach (range(1, $this->dice->int(1, 3)) as $deploy) {
            $schemas[] = $schemaGenerator->expand($releases[$deploy - 1]->schema);
            $releases[] = $releaseGenerator->next($releases[$deploy - 1], $schemas[2 * $deploy - 1]);
            $schemas[] = $releases[$deploy]->schema;
        }

        $this->start(0);
        $this->start(0);
        foreach ($this->operations->seed($releases[0]) as $write) {
            $this->emit(new PodStep('p0', $write));
        }
        $this->phase($releases);

        foreach (array_slice($releases, 1) as $release) {
            $this->deploy($releases, $release);
        }

        $this->endOutage();

        foreach ($this->state->pods as $pod => $release) {
            foreach ($this->reads[$release] ?? [] as $read) {
                $this->emit(new PodStep($pod, $read));
            }
        }

        return new Plan($this->seed, $schemas, $releases, $this->steps);
    }

    /**
     * @param  list<Release>  $releases
     */
    private function deploy(array $releases, Release $release): void
    {
        $previous = array_keys(array_filter($this->state->pods, static fn (int $number): bool => $number < $release->number));
        $contractEarly = $this->dice->chance(10);

        $this->migrate(2 * $release->number - 1);
        $this->phase($releases);

        foreach (range(1, $this->dice->int(1, 2)) as $_) {
            $this->start($release->number);
            $this->phase($releases);
        }

        if ($contractEarly) {
            $this->migrate(2 * $release->number);
            $this->phase($releases);
        }

        foreach ($previous as $pod) {
            if ($this->dice->chance(20)) {
                $this->lifecycle($pod, Lifecycle::Restart);
                $this->phase($releases);
            }

            $this->lifecycle($pod, Lifecycle::Stop);
            $this->phase($releases);
        }

        if (! $contractEarly && $this->dice->chance(85)) {
            $this->migrate(2 * $release->number);
            $this->phase($releases);
        }

        if ($this->dice->chance(15)) {
            $rollback = $this->start($release->number - 1);
            $this->phase($releases);
            $this->lifecycle($rollback, Lifecycle::Stop);
        }
    }

    /**
     * @param  list<Release>  $releases
     */
    private function phase(array $releases): void
    {
        for ($i = 0; $i < $this->stepsPerPhase; $i++) {
            match ($this->dice->weighted(['read' => 25, 'write' => 50, 'transaction' => 12, 'disturb' => 5, 'restart' => 3, 'outage' => 4])) {
                'read' => $this->read($releases, $this->dice->pick(array_keys($this->state->pods))),
                'write' => $this->writeBetweenReads($releases),
                'transaction' => $this->transaction(),
                'disturb' => $this->disturb(),
                'restart' => $this->lifecycle($this->dice->pick(array_keys($this->state->pods)), Lifecycle::Restart),
                'outage' => $this->toggleOutage(),
            };
        }
    }

    /**
     * Pods read what the write may change before and after it, mostly the written table by any
     * of its keys, so a write that misses an invalidation shows as a stale read on whichever pod
     * cached it, whatever release that pod runs.
     *
     * @param  list<Release>  $releases
     */
    private function writeBetweenReads(array $releases): void
    {
        $writer = $this->state->transactionHolder ?? $this->dice->pick(array_keys($this->state->pods));
        $release = $releases[$this->state->pods[$writer]];
        $table = $this->operations->table($release, 3)->name;
        $write = $this->operations->write($release, $this->state->transactionHolder === $writer, $table);
        $probes = [];
        foreach (range(1, $this->dice->int(2, 6)) as $_) {
            $pod = $this->dice->pick(array_keys($this->state->pods));
            $podRelease = $releases[$this->state->pods[$pod]];
            $sameRows = $this->dice->chance(40) ? $this->operations->readOfSameRows($podRelease, $write) : null;
            $sameTable = isset($podRelease->schema->tables[$table]) && $this->dice->chance(75);
            $read = $sameRows ?? ($sameTable ? $this->operations->read($podRelease, $table) : $this->readFor($podRelease));
            $probes[] = new PodStep($pod, $this->remember($podRelease, $read));
        }

        array_map($this->emit(...), $probes);
        $this->emit(new PodStep($writer, $write));
        array_map($this->emit(...), $probes);

        if ($this->state->transactionHolder === $writer) {
            array_push($this->readsAwaitingCommit, ...$probes);
        }
    }

    /**
     * @param  list<Release>  $releases
     */
    private function read(array $releases, string $pod): void
    {
        $this->emit(new PodStep($pod, $this->readFor($releases[$this->state->pods[$pod]])));
    }

    /**
     * A read the release made before, or a new one.
     */
    private function readFor(Release $release): Read
    {
        $known = $this->reads[$release->number] ?? [];

        if ($known !== [] && $this->dice->chance(50)) {
            return $this->dice->pick($known);
        }

        return $this->remember($release, $this->operations->read($release));
    }

    private function remember(Release $release, Read $read): Read
    {
        $this->reads[$release->number][$read->describe()] = $read;

        return $read;
    }

    private function transaction(): void
    {
        $holder = $this->state->transactionHolder;

        if ($holder === null) {
            $this->emit(new PodStep($this->dice->pick(array_keys($this->state->pods)), TransactionAction::Begin));

            return;
        }

        $action = $this->dice->pick([TransactionAction::Commit, TransactionAction::Commit, TransactionAction::Rollback, TransactionAction::Begin]);
        $step = new PodStep($holder, $action);
        $this->emit($this->state->allows($step) ? $step : new PodStep($holder, TransactionAction::Commit));
    }

    /**
     * Redis goes down, or comes back, outside a transaction.
     */
    private function toggleOutage(): void
    {
        $step = new DisturbStep($this->state->redisDown ? Disturbance::Recovery : Disturbance::Outage);

        if ($this->state->allows($step)) {
            $this->emit($step);
        }
    }

    private function endOutage(): void
    {
        $this->endTransaction();

        if ($this->state->redisDown) {
            $this->emit(new DisturbStep(Disturbance::Recovery));
        }
    }

    /**
     * Stops or restarts the pod once Redis is up.
     */
    private function lifecycle(string $pod, Lifecycle $lifecycle): void
    {
        if ($this->state->redisDown) {
            $this->endOutage();
        }

        $this->emit(new LifecycleStep($pod, $lifecycle));
    }

    private function disturb(): void
    {
        $this->emit(new DisturbStep($this->dice->pick([Disturbance::Flush, Disturbance::Empty, Disturbance::Evict]), $this->dice->int(1, PHP_INT_MAX)));
    }

    private function migrate(int $schema): void
    {
        $this->endTransaction();
        $this->emit(new MigrateStep($schema));
    }

    private function endTransaction(): void
    {
        while (($holder = $this->state->transactionHolder) !== null) {
            $this->emit(new PodStep($holder, $this->dice->chance(80) ? TransactionAction::Commit : TransactionAction::Rollback));
        }
    }

    private function start(int $release): string
    {
        $pod = 'p'.$this->pods++;
        $this->emit(new LifecycleStep($pod, Lifecycle::Start, $release, $this->dice->chance(30)));

        return $pod;
    }

    private function emit(Step $step): void
    {
        if (! $this->state->allows($step)) {
            throw new LogicException('The plan generator emitted a step the deploy does not allow: '.$step->describe());
        }

        $holder = $this->state->transactionHolder;
        $this->steps[] = $step;
        $this->state = $this->state->after($step);

        if ($holder !== null && $this->state->transactionHolder === null) {
            $this->readAgainAfterTransaction();
        }
    }

    /**
     * Reads around writes of a transaction that ended, by the pods still running.
     */
    private function readAgainAfterTransaction(): void
    {
        $reads = $this->readsAwaitingCommit;
        $this->readsAwaitingCommit = [];

        foreach ($reads as $read) {
            if (isset($this->state->pods[$read->pod])) {
                $this->emit($read);
            }
        }
    }
}
