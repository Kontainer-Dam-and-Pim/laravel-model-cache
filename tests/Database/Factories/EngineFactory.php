<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kontainer\ModelCache\Tests\Database\Models\Engine;

class EngineFactory extends Factory
{
    /** @var class-string<Engine> */
    protected $model = Engine::class;

    /**
     * {@inheritDoc}
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'car_id' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
