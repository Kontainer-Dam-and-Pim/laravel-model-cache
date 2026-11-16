<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Eloquent;

use Kontainer\ModelCache\Tests\Concerns\MakesTestModels;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Assert;

class ScopesTest extends TestCase
{
    use MakesTestModels;

    protected function seedCars(): void
    {
        $this->makeCar(['name' => 'alpha', 'driver_id' => 5]);
        $this->makeCar(['name' => 'bravo', 'driver_id' => 10]);
        $this->makeCar(['name' => 'charlie', 'driver_id' => 15]);
        $this->makeCar(['name' => 'delta', 'driver_id' => 20]);
    }

    public function testWhereOrWhereAndBetween(): void
    {
        $this->seedCars();

        $a = Car::where('name', 'alpha')->get();
        Assert::assertCount(1, $a);

        $or = Car::where('name', 'alpha')->orWhere('name', 'delta')->get();
        Assert::assertCount(2, $or);

        $between = Car::whereBetween('driver_id', [10, 20])->orderBy('driver_id')->pluck('driver_id')->all();
        Assert::assertSame([10, 15, 20], $between);
    }

    public function testOrderLimitFirstAndFirstOrFailVariants(): void
    {
        $this->seedCars();

        $ordered = Car::orderBy('name', 'desc')->take(2)->pluck('name')->all();
        Assert::assertSame(['delta', 'charlie'], $ordered);

        $first = Car::where('name', 'alpha')->first();
        Assert::assertNotNull($first);
        Assert::assertSame('alpha', $first->name);

        $firstOrFail = Car::where('name', 'bravo')->firstOrFail();
        Assert::assertSame('bravo', $firstOrFail->name);
    }

    public function testFindAndFindOrFail(): void
    {
        $car = $this->makeCar(['name' => 'f0']);

        $found = Car::find($car->id);
        Assert::assertNotNull($found);
        Assert::assertSame($car->id, $found->id);

        $also = Car::findOrFail($car->id);
        Assert::assertSame($car->id, $also->id);
    }
}
