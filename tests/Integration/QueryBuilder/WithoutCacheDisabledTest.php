<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\QueryBuilder;

use Kontainer\ModelCache\Database\QueryBuilder;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;

class WithoutCacheDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Boot Model Cache in the disabled state so DB::extend and singletons stay unregistered.
        $app['config']->set('model-cache.active', false);
    }

    public function testEloquentMacroIsGracefulNoopWhenDisabled(): void
    {
        $builder = Car::query()->where('id', 1);

        // Macro must not throw when the cache is disabled; the underlying query is a vanilla Laravel Builder.
        $returned = $builder->withoutCache();

        $this->assertSame($builder, $returned);
        $this->assertNotInstanceOf(QueryBuilder::class, $builder->getQuery());
    }
}
