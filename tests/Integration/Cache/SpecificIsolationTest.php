<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

final class SpecificIsolationTest extends TestCase
{
    use CacheAssertions;

    public function testUpdateSpecificRowDoesNotInvalidateOtherSpecificRow(): void
    {
        $this->loadDependencyMap();
        // Arrange: seed two rows
        DB::table('cars')->insert([
            ['id' => 10, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 11, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);

        // Build two specific SELECT queries
        $b10 = DB::table('cars')->where('id', 10);
        $b11 = DB::table('cars')->where('id', 11);

        [$k10, $t10] = $this->cacheKeyAndTags($b10);

        [$k11, $t11] = $this->cacheKeyAndTags($b11);

        // Seed cache entries for both queries
        $this->seedCache($k10, $t10, [['id' => 10, 'name' => 'A']]);
        $this->seedCache($k11, $t11, [['id' => 11, 'name' => 'B']]);

        $this->assertCacheHas($k10, $t10, 'Expected specific cache for id=10 to be present before update');
        $this->assertCacheHas($k11, $t11, 'Expected specific cache for id=11 to be present before update');

        // Act: update only the second row
        DB::table('cars')->where('id', 11)->update(['name' => 'B2']);

        // Assert: cache for id=11 should be invalidated, but id=10 should remain
        $this->assertCacheMissing($k11, $t11, 'Expected specific cache for id=11 to be invalidated');
        $this->assertCacheHas($k10, $t10, 'Updating a specific row should not invalidate another specific row');
    }
}
