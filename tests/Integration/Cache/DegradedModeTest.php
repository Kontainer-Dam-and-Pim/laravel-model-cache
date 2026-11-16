<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kontainer\ModelCache\Database\QueryBuilder;
use Kontainer\ModelCache\ModelCacheServiceProvider;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;

final class DegradedModeTest extends TestCase
{
    public function testModelsAndQueryBuilderWorkWhileRedisIsUnreachable(): void
    {
        $this->pointCacheAtUnreachableRedis();

        $car = Car::create(['name' => 'created']);
        $car->update(['name' => 'updated']);

        $this->assertSame('updated', Car::query()->find($car->id)?->name);
        $this->assertSame(1, DB::table('cars')->count());
        $this->assertTrue(DB::table('cars')->where('id', $car->id)->exists());

        DB::transaction(fn () => DB::table('cars')->where('id', $car->id)->delete());

        $this->assertSame(0, Car::query()->count());
    }

    public function testInvalidScopeDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['model-cache.scope' => 'everything']);
    }

    public function testInvalidExpirationJitterDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['model-cache.expiration_jitter' => 150]);
    }

    public function testNonStringPrefixDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['model-cache.prefix' => ['model-cache:']]);
    }

    public function testNonStringRedisConnectionDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['model-cache.redis_connection' => 7]);
    }

    public function testNonStringExcludedTableDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['model-cache.exclude_tables' => ['cars', ['engines']]]);
    }

    /**
     * @param  array<string, mixed>  $invalidConfiguration
     */
    private function assertInvalidConfigurationDisablesTheCache(array $invalidConfiguration): void
    {
        Log::spy();
        DB::table('cars')->insert(['id' => 1, 'name' => 'plain', 'engine_id' => null, 'driver_id' => null]);
        config($invalidConfiguration);

        (new ModelCacheServiceProvider($this->app))->register();

        $this->assertFalse((bool) config('model-cache.active'));
        $this->assertNotInstanceOf(QueryBuilder::class, DB::table('cars'));
        $this->assertSame('plain', Car::query()->find(1)?->name);
        Log::shouldHaveReceived('error')->once();
    }
}
