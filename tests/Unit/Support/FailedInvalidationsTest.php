<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\ColumnKeys;
use Kontainer\ModelCache\Support\FailedInvalidations;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Support\Write;
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

    public function testALaterRequestReplaysAKeyedDeleteAsRecorded(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('Needs APCu with apc.enable_cli=1, as PHP-FPM pools use it across requests.');
        }

        $recorded = Invalidation::ofWrite(['tag'], ['tag'], 0)->withChangesOf('db', 'default', Write::delete()->deletingKeys('owners', 'id', ColumnKeys::of([1, 2])), ['owners']);
        FailedInvalidations::detect('model-cache:test-keyed:', 60)->record($recorded);

        $replayed = null;
        FailedInvalidations::detect('model-cache:test-keyed:', 60)->replay(static function (Invalidation $invalidation) use (&$replayed): void {
            $replayed = $invalidation;
        });

        $this->assertEquals($recorded->keyedSources(), $replayed?->keyedSources());
    }
}
