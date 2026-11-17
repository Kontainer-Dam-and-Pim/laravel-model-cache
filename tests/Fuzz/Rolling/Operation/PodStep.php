<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * A step a pod runs: a read, a write or a transaction boundary.
 */
final readonly class PodStep implements Step
{
    public function __construct(
        public string $pod,
        public Read|Write|TransactionAction $action,
    ) {}

    public function describe(): string
    {
        return "{$this->pod}: ".($this->action instanceof TransactionAction ? $this->action->value : $this->action->describe());
    }

    public function isRead(): bool
    {
        return $this->action instanceof Read;
    }

    public function toArray(): array
    {
        return [
            'type' => 'pod',
            'pod' => $this->pod,
            'read' => $this->action instanceof Read ? $this->action->toArray() : null,
            'write' => $this->action instanceof Write ? $this->action->toArray() : null,
            'transaction' => $this->action instanceof TransactionAction ? $this->action->value : null,
        ];
    }
}
