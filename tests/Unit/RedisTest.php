<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Tests\TestCase;

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
}
