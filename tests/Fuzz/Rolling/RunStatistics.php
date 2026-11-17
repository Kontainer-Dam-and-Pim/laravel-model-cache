<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

/**
 * What a run exercised, so a plan that mostly ran statements the database rejected cannot pass
 * unnoticed.
 */
final class RunStatistics
{
    public int $reads = 0;

    public int $rejectedReads = 0;

    public int $writes = 0;

    public int $rejectedWrites = 0;

    /** @var array<string, int> SQLSTATE => how many writes the database rejected with it */
    public array $rejections = [];

    public int $skippedSteps = 0;

    public int $migrations = 0;

    /** @var array<int, true> */
    public array $releasesRead = [];

    public function describe(): string
    {
        return sprintf(
            '%d reads compared (%d rejected by the database), %d writes (%d rejected: %s), %d migrations, releases read: %s, %d steps skipped',
            $this->reads,
            $this->rejectedReads,
            $this->writes,
            $this->rejectedWrites,
            json_encode($this->rejections),
            $this->migrations,
            implode(', ', array_keys($this->releasesRead)),
            $this->skippedSteps,
        );
    }
}
