<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use InvalidArgumentException;
use Kontainer\ModelCache\Support\ReplicaReads;
use Kontainer\ModelCache\Tests\TestCase;

final class ReplicaReadsTest extends TestCase
{
    public function testANegativeReplicaLagFailsLoudly(): void
    {
        config(['model-cache.replica_lag' => '-1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.replica_lag -1, expected 0 or more seconds');

        ReplicaReads::configuredLagSeconds();
    }
}
