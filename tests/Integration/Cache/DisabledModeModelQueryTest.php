<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;

class DisabledModeModelQueryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Set config to false BEFORE providers are registered
        $app['config']->set('model-cache.active', false);

        return parent::getPackageProviders($app);
    }

    public function testModelQueriesWorkWhenDisabled(): void
    {
        // Verify that model-cache.handler is NOT bound when disabled
        $this->assertFalse($this->app->bound('model-cache.handler'), 'model-cache.handler should not be bound when disabled');

        // Models using ModelCacheTrait should work without exceptions when disabled
        $result = Car::all();

        $this->assertIsIterable($result);
    }
}
