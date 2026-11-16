<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\CacheUnavailableException;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Support\RedisAvailability;
use Kontainer\ModelCache\Tests\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

class RedisTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['model-cache.prefix' => 'model-cache:']);

        $this->redis = new Redis;
    }

    public function testPrefixConcatenatesConfiguredPrefixAndKey(): void
    {
        $this->assertSame('model-cache:users', $this->redis->prefix('users'));
        $this->assertSame('model-cache:*', $this->redis->prefix('*'));
    }

    public function testGetConnectionReturnsInjectedConnection(): void
    {
        $expected = RedisFacade::connection((string) config('model-cache.redis_connection', 'cache'));
        $this->assertSame($expected, $this->redis->getConnection());
        $this->assertInstanceOf(Connection::class, $this->redis->getConnection());
    }

    public function testDynamicCallsAreForwardedToUnderlyingConnection(): void
    {
        // Forward a typical Redis command via proxy and assert round-trip
        $key = 'model-cache:test:key';
        $this->redis->set($key, 'value');
        $this->assertSame('value', $this->redis->get($key));
    }

    public function testAScriptRedisRejectsFailsWithTheServerErrorAndStartsTheCooldown(): void
    {
        $this->redis->rpush('model-cache:generation', 'not a string');
        $cache = new Cache(new Redis(null, RedisAvailability::processLocal()), new Encoder);

        $rejection = $this->failureOf(fn () => $cache->lookup('k', ['t'], []));
        $retry = $this->failureOf(fn () => $cache->lookup('k', ['t'], []));

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('WRONGTYPE', $rejection->getMessage());
        $this->assertInstanceOf(CacheUnavailableException::class, $retry);
    }

    public function testAPhpRedisConnectionWithoutARedisClientIsRejected(): void
    {
        $redis = new Redis(new PhpRedisConnection(new stdClass), RedisAvailability::processLocal());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model Cache supports phpredis connections backed by a Redis client, got stdClass');

        $redis->evaluate('return 1', []);
    }

    public function testAPredisConnectionWithoutAPredisClientIsRejected(): void
    {
        $redis = new Redis(new PredisConnection(new stdClass), RedisAvailability::processLocal());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model Cache supports Predis connections backed by a Predis client, got stdClass');

        $redis->evaluate('return 1', []);
    }

    private function failureOf(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
