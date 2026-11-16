<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Tests\TestCase;

class EnableCommandTest extends TestCase
{
    public function testEnableCommandPrintsMessagesAndSucceeds(): void
    {
        $this->artisan('model-cache:enable')
            ->expectsOutput('Model Cache enabled.')
            ->assertExitCode(0);
    }

    public function testEnablingRetiresResultsCachedBeforeWritesWentUntracked(): void
    {
        $redis = new Redis;
        $redis->set($redis->prefix('generation'), 'cached-before-disabling');

        $this->artisan('model-cache:enable')->assertExitCode(0);

        $this->assertNotSame('cached-before-disabling', $redis->get($redis->prefix('generation')));
    }
}
