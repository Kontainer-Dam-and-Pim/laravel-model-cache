<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Kontainer\ModelCache\Tests\TestCase;

class DisableCommandTest extends TestCase
{
    public function testDisableCommandPrintsMessageAndSucceeds(): void
    {
        $this->artisan('model-cache:disable')
            ->expectsOutput('Model Cache disabled.')
            ->assertExitCode(0);
    }
}
