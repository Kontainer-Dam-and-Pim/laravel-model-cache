<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * One step of a deploy: what a pod runs, a migration, a pod starting, stopping or restarting, or a
 * disturbance of the cache.
 */
interface Step
{
    public function describe(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
