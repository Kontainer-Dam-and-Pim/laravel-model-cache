<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kontainer\ModelCache\Tests\Database\Models\Material;

class MaterialFactory extends Factory
{
    /** @var class-string<Material> */
    protected $model = Material::class;

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
