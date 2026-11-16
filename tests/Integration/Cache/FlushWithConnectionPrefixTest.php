<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\CacheHit;
use Kontainer\ModelCache\CacheMiss;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\ExpirationPolicy;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Tests\TestCase;

class FlushWithConnectionPrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.options.prefix', 'app_');
        $app['config']->set('model-cache.prefix', 'model-cache:');
    }

    public function testEntriesRoundTripUnderConnectionPrefix(): void
    {
        $cache = $this->freshCache();

        $miss = $cache->lookup('k1', ['t1'], []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['row']);

        $hit = $cache->lookup('k1', ['t1'], []);
        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['row'], $hit->rows);
        $this->assertContains('app_model-cache:generation', RedisFacade::connection('cache')->keys('model-cache:*'));
    }

    public function testInvalidationHonoursConnectionPrefix(): void
    {
        $cache = $this->freshCache();
        $this->seedEntry($cache, 'k1', ['t1'], ['row']);

        $cache->invalidate(Invalidation::of(['t1']));

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k1', ['t1'], []));
    }

    public function testFlushDeletesAllEntriesAndKeepsOnlyTheGenerationAndServerKeys(): void
    {
        $cache = $this->freshCache();
        $this->seedEntry($cache, 'k1', ['t1'], ['1']);
        $this->seedEntry($cache, 'k2', ['t2'], ['2']);
        $this->assertGreaterThanOrEqual(5, count(RedisFacade::connection('cache')->keys('model-cache:*')));

        $cache->flush();

        $remaining = RedisFacade::connection('cache')->keys('model-cache:*');
        sort($remaining);
        $this->assertSame(['app_model-cache:generation', 'app_model-cache:server'], $remaining);
    }

    private function freshCache(): Cache
    {
        RedisFacade::purge('cache');

        return new Cache(new Redis, new Encoder, new ExpirationPolicy(0));
    }

    /**
     * @param  array<string>  $tags
     */
    private function seedEntry(Cache $cache, string $key, array $tags, array $rows): void
    {
        $miss = $cache->lookup($key, $tags, []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, $rows);
    }
}
