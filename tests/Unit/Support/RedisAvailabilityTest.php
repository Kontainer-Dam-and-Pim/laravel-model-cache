<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\RedisAvailability;
use Kontainer\ModelCache\Tests\TestCase;

final class RedisAvailabilityTest extends TestCase
{
    public function testARecoveryEndsTheCooldownForLaterRequestsWhenApcuIsAvailable(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('Needs APCu with apc.enable_cli=1, as PHP-FPM pools use it across requests.');
        }

        RedisAvailability::detect('model-cache:test-availability')->markUnavailable();
        $recoveringRequest = RedisAvailability::detect('model-cache:test-availability');
        $this->assertTrue($recoveringRequest->isCoolingDown());

        $recoveringRequest->markAvailable();

        $this->assertFalse(RedisAvailability::detect('model-cache:test-availability')->isCoolingDown());
    }
}
