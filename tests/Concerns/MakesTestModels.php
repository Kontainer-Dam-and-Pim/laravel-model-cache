<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\Database\Models\CarMaterial;
use Kontainer\ModelCache\Tests\Database\Models\Driver;
use Kontainer\ModelCache\Tests\Database\Models\Engine;
use Kontainer\ModelCache\Tests\Database\Models\Material;

trait MakesTestModels
{
    protected function makeCar(array $attributes = [], ?int $count = null): Model|Car
    {
        $factory = Car::factory()->state($attributes);

        return $count ? $factory->count($count)->create() : $factory->create();
    }

    protected function makeDriver(array $attributes = []): Driver
    {
        return Driver::factory()->state($attributes)->create();
    }

    protected function makeEngine(array $attributes = []): Engine
    {
        return Engine::factory()->state($attributes)->create();
    }

    protected function makeMaterial(array $attributes = []): Material
    {
        return Material::factory()->state($attributes)->create();
    }

    protected function makeCarMaterial(array $attributes = []): CarMaterial
    {
        return CarMaterial::factory()->state($attributes)->create();
    }
}
