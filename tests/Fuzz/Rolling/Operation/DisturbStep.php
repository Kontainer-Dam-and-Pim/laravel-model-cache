<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

final readonly class DisturbStep implements Step
{
    /**
     * @param  int  $seed  which keys an eviction picks
     */
    public function __construct(
        public Disturbance $disturbance,
        public int $seed = 0,
    ) {}

    public function describe(): string
    {
        return "redis: {$this->disturbance->value}".($this->disturbance === Disturbance::Evict ? " (seed {$this->seed})" : '');
    }

    public function toArray(): array
    {
        return ['type' => 'disturb', 'disturbance' => $this->disturbance->value, 'seed' => $this->seed];
    }
}
