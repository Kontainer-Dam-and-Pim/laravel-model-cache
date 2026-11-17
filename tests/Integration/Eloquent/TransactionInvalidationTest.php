<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Eloquent;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

final class TransactionInvalidationTest extends TestCase
{
    use CacheAssertions;

    public function testSpecificUpdateInTransactionInvalidatesAfterCommitOnly(): void
    {
        DB::table('cars')->insert(['id' => 601, 'name' => 'TX', 'engine_id' => null, 'driver_id' => null]);

        $this->loadDependencyMap();
        [$k, $t] = $this->cacheKeyAndTags(DB::table('cars')->where('id', 601));
        $this->seedCache($k, $t, [['id' => 601, 'name' => 'TX']]);
        $this->assertCacheHas($k, $t);

        DB::beginTransaction();
        DB::table('cars')->where('id', 601)->update(['name' => 'TX2']);
        // Should still be present before commit
        $this->assertCacheHas($k, $t);
        DB::commit();

        $this->assertCacheMissing($k, $t);
    }
}
