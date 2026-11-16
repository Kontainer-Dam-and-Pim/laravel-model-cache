<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;

final class InvalidateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        DB::table('drivers')->insert(['id' => 1, 'name' => 'before']);
    }

    public function testInvalidatesRowAndTableReadsOfTheNamedTablesOnly(): void
    {
        $this->loadDependencyMap();
        $this->assertSame('before', Car::query()->find(1)?->name);
        $this->assertSame(['before'], DB::table('cars')->pluck('name')->all());
        $this->assertSame('before', DB::table('drivers')->where('id', 1)->value('name'));
        $this->writeBypassingLaravel("update cars set name = 'after'");
        $this->writeBypassingLaravel("update drivers set name = 'after'");

        $this->artisan('model-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutput('Invalidated the cached reads of: cars')
            ->assertExitCode(0);

        $this->assertSame('after', Car::query()->find(1)?->name);
        $this->assertSame(['after'], DB::table('cars')->pluck('name')->all());
        $this->assertSame('before', DB::table('drivers')->where('id', 1)->value('name'));
    }

    public function testRejectsUnknownTablesWithoutInvalidatingAnything(): void
    {
        $this->assertSame('before', Car::query()->find(1)?->name);
        $this->writeBypassingLaravel("update cars set name = 'after'");

        $this->artisan('model-cache:invalidate', ['tables' => ['cars', 'carz']])
            ->expectsOutput('Unknown tables on connection '.DB::getDefaultConnection().': carz')
            ->assertExitCode(1);

        $this->assertSame('before', Car::query()->find(1)?->name);
    }

    public function testFailsWhileRedisRejectsTheInvalidation(): void
    {
        $this->app['config']->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'read_write_timeout' => 0.2]);
        config(['model-cache.redis_connection' => 'unreachable']);
        foreach (['model-cache.redis', 'model-cache.cache', 'model-cache.handler'] as $binding) {
            $this->app->forgetInstance($binding);
        }

        $this->artisan('model-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutput('Redis rejected the invalidation; it is kept and replayed once Redis accepts it.')
            ->assertExitCode(1);
    }

    public function testFailsWhenDisabled(): void
    {
        config(['model-cache.active' => false]);

        $this->artisan('model-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutput('Model Cache is disabled. Nothing was invalidated.')
            ->assertExitCode(1);
    }

    private function writeBypassingLaravel(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
}
