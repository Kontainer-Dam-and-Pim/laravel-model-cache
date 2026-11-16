<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\ColumnKeys;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Support\RowKeys;
use Kontainer\ModelCache\Support\Write;
use PHPUnit\Framework\TestCase;

final class InvalidationTest extends TestCase
{
    public function testManyRowTagsCollapseIntoTheTableTagsCoveringThem(): void
    {
        $merged = Invalidation::none();
        foreach (range(1, Invalidation::MAX_TAGS + 1) as $id) {
            $merged = $merged->merge(Invalidation::ofWrite(["cars:row:{$id}", 'cars:unspecific'], ['cars:specific', 'cars:unspecific'], 0));
        }

        $this->assertNull($merged->retires);
        $this->assertSame(['cars:specific', 'cars:unspecific'], $merged->tags());
    }

    public function testCollapsingKeepsCascadesAndReplicaLag(): void
    {
        $merged = Invalidation::ofWrite(['seed'], ['cars:wide'], 7)->withChangesOf('db', 'default', Write::delete(), ['cars']);
        foreach (range(1, Invalidation::MAX_TAGS + 1) as $id) {
            $merged = $merged->merge(Invalidation::ofWrite(["cars:row:{$id}"], ['cars:wide'], 0));
        }

        $this->assertSame(['cars:wide'], $merged->tags());
        $this->assertSame(['db' => ['a:cars', 'd:cars', 'gd:cars']], $merged->cascadeSources());
        $this->assertSame(7, $merged->replicaLagSeconds);
    }

    public function testManyTagsWithoutKnownCoverageRetireTheCache(): void
    {
        $merged = Invalidation::of(['opaque']);
        foreach (range(1, Invalidation::MAX_TAGS + 1) as $id) {
            $merged = $merged->merge(Invalidation::ofWrite(["cars:row:{$id}"], ['cars:wide'], 0));
        }

        $this->assertNotNull($merged->retires);
    }

    public function testFewTagsStayRowSpecific(): void
    {
        $merged = Invalidation::ofWrite(['cars:row:1'], ['cars:wide'], 0)->merge(Invalidation::ofWrite(['cars:row:2'], ['cars:wide'], 0));

        $this->assertSame(['cars:row:1', 'cars:row:2'], $merged->tags());
    }

    public function testWritesToNoTablesChangeNothingAlongWithThem(): void
    {
        $invalidation = Invalidation::none()->withChangesOf('db', 'default', Write::delete(), []);

        $this->assertTrue($invalidation->isEmpty());
        $this->assertSame([], $invalidation->cascadeSources());
    }

    public function testKeyedDeletesWithTooManyKeysTogetherChangeWhatAnyDeleteChanges(): void
    {
        $deleting = static fn (array $ids): Invalidation => Invalidation::none()->withChangesOf('db', 'default', Write::delete()->deletingKeys('owners', 'id', ColumnKeys::of($ids)), ['owners']);
        $first = $deleting(range(1, RowKeys::MAX_PER_TABLE));
        $this->assertSame(['db' => ['a:owners', 'gd:owners']], $first->cascadeSources());

        $merged = $first->merge($deleting(range(RowKeys::MAX_PER_TABLE + 1, 2 * RowKeys::MAX_PER_TABLE)));

        $this->assertSame([], $merged->keyedSources());
        $this->assertSame(['db' => ['a:owners', 'd:owners', 'gd:owners']], $merged->cascadeSources());
    }
}
