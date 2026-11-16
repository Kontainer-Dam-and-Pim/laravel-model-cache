<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Kontainer\ModelCache\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['model-cache.enable_debugbar' => false]);
    }

    public function testFlushIsNoOpWhenDisabled(): void
    {
        config(['model-cache.active' => false]);

        $this->artisan('model-cache:flush')
            ->expectsOutput('Model Cache is disabled. Nothing to flush.')
            ->assertExitCode(0);
    }

    public function testFlushCallsCacheAndSucceeds(): void
    {
        config(['model-cache.active' => true]);

        $this->artisan('model-cache:flush')
            ->expectsOutput('Model Cache has been flushed successfully.')
            ->assertExitCode(0);
    }
}
