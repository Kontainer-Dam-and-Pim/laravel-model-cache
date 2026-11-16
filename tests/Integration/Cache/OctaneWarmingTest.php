<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Kontainer\ModelCache\Tests\TestCase;

final class OctaneWarmingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('octane.warm', ['view']);
    }

    public function testCacheServicesOutliveRequestsUnderOctane(): void
    {
        $this->assertSame(['view', 'model-cache.redis', 'model-cache.metrics', 'model-cache.cache', 'model-cache.handler'], config('octane.warm'));
    }
}
