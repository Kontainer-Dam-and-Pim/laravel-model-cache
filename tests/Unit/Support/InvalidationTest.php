<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Kontainer\ModelCache\Support\ColumnKeys;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Support\RowKeys;
use Kontainer\ModelCache\Support\RowWrite;
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

    public function testMergedWritesOfATableNameRowsOnlyByColumnsNamingThemInEveryWrite(): void
    {
        $byId = Invalidation::ofWrite(['cars:unspecific'], ['cars:wide'], 0, [new RowWrite('db', 'cars', ['id' => ColumnKeys::of([1]), 'name' => ColumnKeys::of(['a'])])]);
        $byIdAgain = Invalidation::ofWrite(['cars:unspecific'], ['cars:wide'], 0, [new RowWrite('db', 'cars', ['id' => ColumnKeys::of([2])])]);
        $otherTable = Invalidation::ofWrite(['drivers:unspecific'], ['drivers:wide'], 0, [new RowWrite('db', 'drivers', [])]);

        $merged = $byId->merge($byIdAgain)->merge($otherTable);

        $this->assertEquals(['db' => [
            new RowWrite('db', 'cars', ['id' => ColumnKeys::of([1, 2])]),
            new RowWrite('db', 'drivers', []),
        ]], $merged->rowWrites());
    }

    public function testManyRowKeysCollapseIntoTheTableTagsCoveringThem(): void
    {
        $tables = array_map(static fn (int $table): string => "t{$table}", range(0, intdiv(Invalidation::MAX_TAGS, RowKeys::MAX_PER_TABLE)));
        $merged = Invalidation::none();
        foreach ($tables as $table) {
            $merged = $merged->merge(Invalidation::ofWrite([], ["{$table}:wide"], 0, [new RowWrite('db', $table, ['id' => ColumnKeys::of(range(1, RowKeys::MAX_PER_TABLE))])]));
        }

        $this->assertSame([], $merged->rowWrites());
        $this->assertEqualsCanonicalizing(array_map(static fn (string $table): string => "{$table}:wide", $tables), $merged->tags());
    }

    public function testAColumnNamingTooManyRowsNamesNone(): void
    {
        $merged = Invalidation::ofWrite([], ['cars:wide'], 0, [new RowWrite('db', 'cars', ['id' => ColumnKeys::of(range(1, RowKeys::MAX_PER_TABLE))])])
            ->merge(Invalidation::ofWrite([], ['cars:wide'], 0, [new RowWrite('db', 'cars', ['id' => ColumnKeys::of([RowKeys::MAX_PER_TABLE + 1])])]));

        $this->assertEquals(['db' => [new RowWrite('db', 'cars', [])]], $merged->rowWrites());
    }

    public function testRowWritesSurviveSerialization(): void
    {
        $invalidation = Invalidation::ofWrite(['cars:unspecific'], ['cars:wide'], 0, [new RowWrite('db', 'cars', ['id' => ColumnKeys::of([1])])]);

        $this->assertEquals($invalidation, unserialize(serialize($invalidation), ['allowed_classes' => Invalidation::SERIALIZED_CLASSES]));
    }
}
