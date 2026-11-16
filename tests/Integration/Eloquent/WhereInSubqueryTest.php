<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Eloquent;

use Illuminate\Database\Query\Builder;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\Database\Models\Driver;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * A whereIn subquery reads its own table, so writes to that table must invalidate the outer query.
 */
final class WhereInSubqueryTest extends TestCase
{
    public function testEloquentSubqueryReadIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereIn('id', Car::query()->select('driver_id'))
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(1, $count());
    }

    public function testClosureSubqueryReadIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereIn('id', fn (Builder $query) => $query->from('cars')->select('driver_id'))
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(1, $count());
    }

    public function testWhereNotInSubqueryReadIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereNotIn('id', Car::query()->select('driver_id'))
            ->count();

        $this->assertSame(1, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(0, $count());
    }
}
