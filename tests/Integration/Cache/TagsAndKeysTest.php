<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

class TagsAndKeysTest extends TestCase
{
    use CacheAssertions;

    public function testSelectWithoutRowsHasUnspecificTag(): void
    {
        $this->loadDependencyMap();

        $this->assertSame([$this->tableTag('unspecific', 'cars')], $this->resolvedReadTags(DB::table('cars')));
    }

    public function testSelectWithRowsHasSpecificAndRowTags(): void
    {
        $this->loadDependencyMap();

        $this->assertSame(
            [$this->tableTag('specific', 'cars'), $this->tableTag('specific', 'cars').':row:1717'],
            $this->resolvedReadTags(DB::table('cars')->where('id', 1717)),
        );
    }

    public function testSelectWithRowsBeforeTheDependencyMapIsKnownHasUnspecificTag(): void
    {
        $this->assertSame([$this->tableTag('unspecific', 'cars')], $this->resolvedReadTags(DB::table('cars')->where('id', 1717)));
    }

    public function testTruncateRefreshesRowAndTableReads(): void
    {
        DB::table('cars')->insert(['id' => 1717, 'name' => 'T', 'engine_id' => null, 'driver_id' => null]);
        $this->loadDependencyMap();
        $this->assertSame('T', DB::table('cars')->where('id', 1717)->value('name'));
        $this->assertSame(1, DB::table('cars')->count());

        DB::table('cars')->truncate();

        $this->assertNull(DB::table('cars')->where('id', 1717)->value('name'));
        $this->assertSame(0, DB::table('cars')->count());
    }

    private function tableTag(string $kind, string $table): string
    {
        return 'tags:database:'.DB::connection()->getDatabaseName().":table_{$kind}:{$table}";
    }
}
