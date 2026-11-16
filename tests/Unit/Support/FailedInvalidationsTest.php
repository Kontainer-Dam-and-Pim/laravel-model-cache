<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\FailedInvalidations;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Tests\TestCase;

final class FailedInvalidationsTest extends TestCase
{
    public function testRecordingNothingLeavesNothingPending(): void
    {
        $failed = FailedInvalidations::processLocal();

        $failed->record(Invalidation::none());

        $this->assertFalse($failed->hasPending());
    }

    public function testRecordingNothingLeavesNothingPendingForLaterRequestsWhenApcuIsAvailable(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('Needs APCu with apc.enable_cli=1, as PHP-FPM pools use it across requests.');
        }

        FailedInvalidations::detect('model-cache:test-nothing:', 60)->record(Invalidation::none());

        $this->assertFalse(FailedInvalidations::detect('model-cache:test-nothing:', 60)->hasPending());
    }
}
