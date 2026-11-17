<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

final readonly class LifecycleStep implements Step
{
    public function __construct(
        public string $pod,
        public Lifecycle $lifecycle,
        public int $release = 0,
    ) {}

    public function describe(): string
    {
        return "{$this->pod}: {$this->lifecycle->value}".($this->lifecycle === Lifecycle::Start ? " release {$this->release}" : '');
    }

    public function toArray(): array
    {
        return ['type' => 'lifecycle', 'pod' => $this->pod, 'lifecycle' => $this->lifecycle->value, 'release' => $this->release];
    }
}
