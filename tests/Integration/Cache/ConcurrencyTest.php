<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\CacheMiss;
use Kontainer\ModelCache\Redis as CacheRedis;
use Kontainer\ModelCache\Tagger;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use CacheAssertions;

    public function testResultReadBeforeAConcurrentWriteIsNeverServed(): void
    {
        DB::table('cars')->insert(['id' => 910, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 910);
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $cache = app('model-cache.cache');

        $slowReader = $this->lookupRead($key, $tags);
        $this->assertInstanceOf(CacheMiss::class, $slowReader);
        $rowsReadBeforeWrite = [['id' => 910, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]];

        DB::table('cars')->where('id', 910)->update(['name' => 'after']);
        $cache->store($slowReader, $rowsReadBeforeWrite);

        $this->assertCacheMissing($key, $tags);
        $this->assertSame('after', DB::table('cars')->where('id', 910)->value('name'));
    }

    public function testEvictedTagVersionMakesDependentEntriesMiss(): void
    {
        DB::table('cars')->insert(['id' => 911, 'name' => 'X', 'engine_id' => null, 'driver_id' => null]);
        $this->loadDependencyMap();
        $builder = DB::table('cars')->where('id', 911);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        $redis = new CacheRedis;
        $generation = (string) $redis->get($redis->prefix('generation'));
        $redis->del($redis->prefix($generation.':v:'.Tagger::tableSpecificTagPrefix(DB::connection()->getDatabaseName()).'cars'.Tagger::ROW_INFIX.'911'));

        $this->assertCacheMissing($key, $tags);
    }

    public function testEvictedGenerationKeyMakesEveryEntryMiss(): void
    {
        DB::table('cars')->insert(['id' => 912, 'name' => 'X', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 912);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        $redis = new CacheRedis;
        $redis->del($redis->prefix('generation'));

        $this->assertCacheMissing($key, $tags);
    }
}
