<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\CacheMiss;
use Kontainer\ModelCache\Database\QueryBuilder;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\ExpirationPolicy;
use Kontainer\ModelCache\Manager;
use Kontainer\ModelCache\Redis as CacheRedis;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

class ConfigurationBehaviourTest extends TestCase
{
    use CacheAssertions;

    public function testActiveFalseDisablesCaching(): void
    {
        config(['model-cache.active' => false]);

        $builder = DB::table('cars');

        // When inactive, DB::table() returns Laravel's default builder, not the caching one
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertNotInstanceOf(QueryBuilder::class, $builder);
    }

    public function testIncludeTablesOnlyCachesIncluded(): void
    {
        config(['model-cache.active' => true, 'model-cache.include_tables' => ['cars'], 'model-cache.exclude_tables' => []]);

        $carsManager = new Manager(new Reflector(DB::table('cars')));
        $driversManager = new Manager(new Reflector(DB::table('drivers')));

        $this->assertTrue($carsManager->shouldCache());
        $this->assertFalse($driversManager->shouldCache());
    }

    public function testConsiderRowsTogglesRowSpecificTags(): void
    {
        $this->loadDependencyMap();
        $builder = DB::table('cars')->where('id', 777);
        $dbName = DB::connection()->getDatabaseName();

        config(['model-cache.consider_rows' => true]);
        $this->assertSame(
            ["tags:database:{$dbName}:table_specific:cars", "tags:database:{$dbName}:table_specific:cars:row:777"],
            $this->resolvedReadTags($builder),
        );

        config(['model-cache.consider_rows' => false]);
        $this->assertSame(["tags:database:{$dbName}:table_unspecific:cars"], $this->resolvedReadTags($builder));
    }

    public function testPrefixAppliedToKeys(): void
    {
        config(['model-cache.prefix' => 'test:']);
        $redis = new CacheRedis;
        $this->assertSame('test:foo', $redis->prefix('foo'));
    }

    public function testExpirationTimeControlsEntryAndVersionTtl(): void
    {
        config(['model-cache.prefix' => 'cfg:']);
        $redis = new CacheRedis;
        $cache = new Cache($redis, new Encoder, new ExpirationPolicy(60));

        $miss = $cache->lookup('expire-key', ['tag'], []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['x' => 1]);

        $entryTtl = (int) $redis->ttl($redis->prefix($miss->generation->id.':e:expire-key'));
        $versionTtl = (int) $redis->ttl($redis->prefix($miss->generation->id.':v:tag'));
        $this->assertGreaterThan(0, $entryTtl);
        $this->assertLessThanOrEqual(60, $entryTtl);
        $this->assertGreaterThan(0, $versionTtl);
        $this->assertLessThanOrEqual(60, $versionTtl);
    }

    public function testPackageExpirationTimeDefaultsToSevenDays(): void
    {
        $packageConfig = require dirname(__DIR__, 3).'/config/model-cache.php';

        $this->assertSame(604800, $packageConfig['expiration_time']);
        $this->assertSame(15, $packageConfig['expiration_jitter']);
    }

    public function testRedisConnectionRespected(): void
    {
        // Use default connection to avoid missing connection names
        config(['model-cache.redis_connection' => 'default']);
        $caching = new CacheRedis;
        $connA = $caching->getConnection();
        $connB = RedisFacade::connection('default');
        $this->assertSame(get_class($connB), get_class($connA));
    }

    public function testDriverConfigReadable(): void
    {
        config(['model-cache.driver' => 'redis']);
        $this->assertSame('redis', config('model-cache.driver'));
    }

    public function testEnableDebugbarToggleDoesNotBreakQueries(): void
    {
        config(['model-cache.enable_debugbar' => false]);
        DB::table('cars')->get();
        $this->assertTrue(true);

        config(['model-cache.enable_debugbar' => true]);
        // Bind a fake debugbar to avoid missing service
        $this->app->instance('debugbar', new class
        {
            public function addCollector($c): void
            {
                $this->collectors[] = $c;
            }
        });

        // Run a query to ensure no exceptions are thrown
        DB::table('cars')->get();
        $this->assertTrue(true);
    }
}
