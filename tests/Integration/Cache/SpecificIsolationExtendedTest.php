<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Assert;

final class SpecificIsolationExtendedTest extends TestCase
{
    use CacheAssertions;

    public function testSpecificUpdateOnOneTableDoesNotInvalidateOtherRowsOrTables(): void
    {
        $this->loadDependencyMap();
        // Seed two cars and one driver row. We'll cache car rows; driver stays untouched but ensures multi-table scenario.
        DB::table('cars')->insert([
            ['id' => 10, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 11, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);
        DB::table('drivers')->insert(['id' => 501, 'name' => 'DRV']);

        $b10 = DB::table('cars')->where('id', 10);
        $b11 = DB::table('cars')->where('id', 11);

        [$k10, $t10] = $this->cacheKeyAndTags($b10);

        [$k11, $t11] = $this->cacheKeyAndTags($b11);
        $this->seedCache($k10, $t10, [['id' => 10, 'name' => 'A']]);
        $this->seedCache($k11, $t11, [['id' => 11, 'name' => 'B']]);

        $this->assertCacheHas($k10, $t10);
        $this->assertCacheHas($k11, $t11);

        // Specific update on cars.id=11 must not evict cached row id=10
        DB::table('cars')->where('id', 11)->update(['name' => 'B2']);

        $this->assertCacheHas($k10, $t10, 'Specific update should not evict other specific rows');
        $this->assertCacheMissing($k11, $t11, 'Row 11 cache should be invalidated');
    }

    public function testUpdateWhereInInvalidatesOnlyListedRows(): void
    {
        $this->loadDependencyMap();
        DB::table('cars')->insert([
            ['id' => 20, 'name' => 'X', 'engine_id' => null, 'driver_id' => null],
            ['id' => 21, 'name' => 'Y', 'engine_id' => null, 'driver_id' => null],
            ['id' => 22, 'name' => 'Z', 'engine_id' => null, 'driver_id' => null],
        ]);

        $mk = function (int $id): array {
            $b = DB::table('cars')->where('id', $id);

            return $this->cacheKeyAndTags($b);
        };

        [$k20, $t20] = $mk(20);
        [$k21, $t21] = $mk(21);
        [$k22, $t22] = $mk(22);
        $this->seedCache($k20, $t20, [['id' => 20, 'name' => 'X']]);
        $this->seedCache($k21, $t21, [['id' => 21, 'name' => 'Y']]);
        $this->seedCache($k22, $t22, [['id' => 22, 'name' => 'Z']]);

        $this->assertCacheHas($k20, $t20);
        $this->assertCacheHas($k21, $t21);
        $this->assertCacheHas($k22, $t22);

        DB::table('cars')->whereIn('id', [20, 22])->update(['name' => 'UPD']);

        $this->assertCacheMissing($k20, $t20);
        $this->assertCacheHas($k21, $t21);
        $this->assertCacheMissing($k22, $t22);

        // Verify DB reflect changes for sanity
        Assert::assertSame('Y', DB::table('cars')->where('id', 21)->value('name'));
    }
}
