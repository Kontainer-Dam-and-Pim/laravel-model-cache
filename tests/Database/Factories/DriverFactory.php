<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kontainer\ModelCache\Tests\Database\Models\Driver;

class DriverFactory extends Factory
{
    /** @var class-string<Driver> */
    protected $model = Driver::class;

    /**
     * {@inheritDoc}
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
        ];
    }
}
