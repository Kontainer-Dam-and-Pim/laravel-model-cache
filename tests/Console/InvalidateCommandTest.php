<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;
use PDO;

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
        $this->pointCacheAtUnreachableRedis();

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

    public function testFailsOnAnUnknownConnection(): void
    {
        $this->artisan('model-cache:invalidate', ['tables' => ['cars'], '--connection' => 'missing'])
            ->expectsOutput('Unknown database connection: Database connection [missing] not configured.')
            ->assertExitCode(1);
    }

    public function testFailsOnAConnectionThatDoesNotUseModelCache(): void
    {
        config(['database.connections.plain' => ['driver' => 'plain', 'database' => ':memory:', 'prefix' => '']]);
        DB::extend('plain', static fn (array $config, string $name): SQLiteConnection => new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));

        $this->artisan('model-cache:invalidate', ['tables' => ['cars'], '--connection' => 'plain'])
            ->expectsOutput('Connection plain does not use Model Cache.')
            ->assertExitCode(1);
    }

    private function writeBypassingLaravel(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
}
