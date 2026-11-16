<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use InvalidArgumentException;
use Kontainer\ModelCache\Metrics;
use Kontainer\ModelCache\Tests\TestCase;

final class MetricsTest extends TestCase
{
    public function testABucketShorterThanASecondFailsLoudly(): void
    {
        config(['model-cache.metrics_bucket_seconds' => '0']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.metrics_bucket_seconds 0, expected 1 or more seconds');

        Metrics::fromConfig();
    }

    public function testARetentionShorterThanTheBucketFailsLoudly(): void
    {
        config(['model-cache.metrics_bucket_seconds' => 3600, 'model-cache.metrics_retention_seconds' => 1800]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.metrics_retention_seconds 1800, expected at least the bucket of 3600 seconds');

        Metrics::fromConfig();
    }
}
