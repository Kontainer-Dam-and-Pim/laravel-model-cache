<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

final readonly class LifecycleStep implements Step
{
    /**
     * @param  bool  $secondConnection  whether the pod reaches the database through a second connection name, as
     *                                  code using a named connection to the same database does
     */
    public function __construct(
        public string $pod,
        public Lifecycle $lifecycle,
        public int $release = 0,
        public bool $secondConnection = false,
    ) {}

    public function describe(): string
    {
        $start = $this->lifecycle === Lifecycle::Start ? " release {$this->release}".($this->secondConnection ? ' on the second connection' : '') : '';

        return "{$this->pod}: {$this->lifecycle->value}{$start}";
    }

    public function toArray(): array
    {
        return ['type' => 'lifecycle', 'pod' => $this->pod, 'lifecycle' => $this->lifecycle->value, 'release' => $this->release, 'secondConnection' => $this->secondConnection];
    }
}
