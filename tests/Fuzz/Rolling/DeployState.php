<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Disturbance;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\DisturbStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Lifecycle;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\LifecycleStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\MigrateStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\PodStep;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Step;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\TransactionAction;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation\Write;

/**
 * Which pods run which release, which pod holds a transaction and how deeply, whether Redis is
 * down, and which schema the database has. Steps run one at a time, so while a pod holds a
 * transaction the others only read, as a write waiting for its row locks would never be let
 * through, and the migration job waits for it to end. Redis goes down or comes back only outside
 * transactions, whose invalidations wait in the services that saw their writes, and pods stop or
 * restart only while it is up, since the invalidations a pod could not apply die with it.
 */
final readonly class DeployState
{
    public const int MAX_TRANSACTION_DEPTH = 2;

    /**
     * @param  array<string, int>  $pods  pod => release
     */
    public function __construct(
        public array $pods = [],
        public ?string $transactionHolder = null,
        public int $transactionDepth = 0,
        public int $schema = 0,
        public bool $redisDown = false,
    ) {}

    public function allows(Step $step): bool
    {
        return match (true) {
            $step instanceof PodStep => $this->allowsPodStep($step),
            $step instanceof LifecycleStep => $step->lifecycle === Lifecycle::Start ? ! isset($this->pods[$step->pod]) : isset($this->pods[$step->pod]) && ! $this->redisDown,
            $step instanceof MigrateStep => $this->transactionHolder === null,
            $step instanceof DisturbStep => match ($step->disturbance) {
                Disturbance::Outage => ! $this->redisDown && $this->transactionHolder === null,
                Disturbance::Recovery => $this->redisDown && $this->transactionHolder === null,
                default => true,
            },
        };
    }

    private function allowsPodStep(PodStep $step): bool
    {
        if (! isset($this->pods[$step->pod])) {
            return false;
        }

        $holds = $this->transactionHolder === $step->pod;

        return match (true) {
            $step->action instanceof Write => $this->transactionHolder === null || $holds,
            $step->action === TransactionAction::Begin => $this->transactionHolder === null || ($holds && $this->transactionDepth < self::MAX_TRANSACTION_DEPTH),
            $step->action instanceof TransactionAction => $holds,
            default => true,
        };
    }

    /**
     * The state once every pod committed the transactions it left open.
     */
    public function settled(): self
    {
        return new self($this->pods, null, 0, $this->schema, $this->redisDown);
    }

    public function after(Step $step): self
    {
        if ($step instanceof MigrateStep) {
            return new self($this->pods, null, 0, $step->schema, $this->redisDown);
        }

        if ($step instanceof LifecycleStep) {
            $pods = $this->pods;
            $released = $this->transactionHolder === $step->pod;

            if ($step->lifecycle === Lifecycle::Start) {
                $pods[$step->pod] = $step->release;
            } elseif ($step->lifecycle === Lifecycle::Stop) {
                unset($pods[$step->pod]);
            }

            return new self($pods, $released ? null : $this->transactionHolder, $released ? 0 : $this->transactionDepth, $this->schema, $this->redisDown);
        }

        if ($step instanceof DisturbStep && in_array($step->disturbance, [Disturbance::Outage, Disturbance::Recovery], true)) {
            return new self($this->pods, $this->transactionHolder, $this->transactionDepth, $this->schema, $step->disturbance === Disturbance::Outage);
        }

        if (! $step instanceof PodStep || ! $step->action instanceof TransactionAction) {
            return $this;
        }

        $depth = match ($step->action) {
            TransactionAction::Begin => $this->transactionDepth + 1,
            TransactionAction::Commit, TransactionAction::Rollback => $this->transactionDepth - 1,
        };

        return new self($this->pods, $depth === 0 ? null : $step->pod, $depth, $this->schema, $this->redisDown);
    }
}
