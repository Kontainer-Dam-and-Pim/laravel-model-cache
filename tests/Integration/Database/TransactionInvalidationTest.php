<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

final class TransactionInvalidationTest extends TestCase
{
    use CacheAssertions;

    public function testInvalidationIsQueuedInsideTransactionAndFlushedOnCommit(): void
    {
        DB::table('cars')->insert(['id' => 10, 'name' => 'T', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 10);
        $builder->get();

        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags, 'Expected key to exist after initial cached select');

        DB::beginTransaction();
        try {
            DB::table('cars')->where('id', 10)->update(['name' => 'T2']);

            $this->assertCacheHas($key, $tags, 'Expected key to still exist before transaction commit (invalidation queued)');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertCacheMissing($key, $tags, 'Expected key to be invalidated after transaction commit');
    }

    public function testQueuedInvalidationsAreClearedOnRollback(): void
    {
        DB::table('cars')->insert(['id' => 11, 'name' => 'R', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 11);
        $builder->get();

        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags, 'Expected key to exist after initial cached select');

        DB::beginTransaction();
        try {
            DB::table('cars')->where('id', 11)->update(['name' => 'R2']);
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertCacheHas($key, $tags, 'Expected key to remain after rollback (queued invalidations cleared)');
    }

    /**
     * MySQL and MariaDB roll back the whole transaction on a deadlock while Laravel only leaves
     * one level of it, so later writes commit as they run although Laravel still counts the
     * transaction as open, and may never reach a commit that would apply their invalidations.
     */
    public function testWritesAfterTheServerEndedTheTransactionInvalidateAtOnce(): void
    {
        if (DB::getDriverName() === 'sqlite' && PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('PDO SQLite reports the transaction state of the database from PHP 8.4 on');
        }

        DB::table('cars')->insert(['id' => 12, 'name' => 'D', 'engine_id' => null, 'driver_id' => null]);
        $name = static fn (): array => DB::table('cars')->where('id', 12)->pluck('name')->all();
        $this->assertSame(['D'], $name());

        DB::beginTransaction();
        DB::getPdo()->exec('ROLLBACK');
        DB::table('cars')->where('id', 12)->update(['name' => 'D2']);

        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(['D2'], $name());

        DB::rollBack();

        $this->assertSame(['D2'], $name());
    }
}
