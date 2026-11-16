<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kontainer\ModelCache\Manager;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Tests\Database\Models\Car;
use Kontainer\ModelCache\Tests\TestCase;

class ManagerTest extends TestCase
{
    private function makeReflectorFromTables(array $tables): Reflector
    {
        // Use first table as FROM and join remaining tables (no execution occurs)
        $first = array_shift($tables) ?? 'cars';
        $builder = DB::table($first);
        foreach ($tables as $t) {
            $builder->join($t, "$first.id", '=', "$t.id");
        }

        return new Reflector($builder);
    }

    public function testShouldCacheTrueWhenActiveAndTablesAllowed(): void
    {
        config([
            'model-cache.active' => true,
            'model-cache.include_tables' => [],
            'model-cache.exclude_tables' => [],
        ]);

        $manager = new Manager($this->makeReflectorFromTables(['cars']));
        $this->assertTrue($manager->shouldCache());
    }

    public function testQueryReadingNoTableIsCachedEvenWhenOnlySomeTablesAreIncluded(): void
    {
        config([
            'model-cache.active' => true,
            'model-cache.include_tables' => ['cars'],
            'model-cache.exclude_tables' => [],
        ]);

        $manager = new Manager(new Reflector(DB::query()->selectRaw('1 as one')));

        $this->assertTrue($manager->shouldCache());
    }

    public function testIncludeTablesRequiresAllTablesToBeIncluded(): void
    {
        config([
            'model-cache.active' => true,
            'model-cache.include_tables' => ['cars', 'drivers'],
            'model-cache.exclude_tables' => [],
        ]);

        $managerAllIncluded = new Manager($this->makeReflectorFromTables(['cars', 'drivers']));
        $this->assertTrue($managerAllIncluded->shouldCache());

        $managerPartial = new Manager($this->makeReflectorFromTables(['cars', 'engines']));
        $this->assertFalse($managerPartial->shouldCache());
    }

    public function testExcludeTablesBlocksWhenAnyTableIsExcluded(): void
    {
        config([
            'model-cache.active' => true,
            'model-cache.include_tables' => [],
            'model-cache.exclude_tables' => ['drivers'],
        ]);

        $managerAllowed = new Manager($this->makeReflectorFromTables(['cars']));
        $this->assertTrue($managerAllowed->shouldCache());

        $managerBlocked = new Manager($this->makeReflectorFromTables(['cars', 'drivers']));
        $this->assertFalse($managerBlocked->shouldCache());
    }

    public function testInactiveDisablesCachingRegardlessOfTables(): void
    {
        // Temporarily enable to get a caching query builder, then test Manager with inactive config
        config(['model-cache.active' => true]);
        $reflector = $this->makeReflectorFromTables(['cars']);

        // Now set inactive and test that Manager respects it
        config([
            'model-cache.active' => false,
            'model-cache.include_tables' => ['cars'],
            'model-cache.exclude_tables' => [],
        ]);

        $manager = new Manager($reflector);
        $this->assertFalse($manager->shouldCache());
    }

    public function testModelsScopeCachesOnlyModelQueries(): void
    {
        config(['model-cache.scope' => 'models']);

        $this->assertFalse((new Manager($this->makeReflectorFromTables(['cars'])))->shouldCache());
        $this->assertTrue((new Manager(new Reflector(Car::query()->getQuery())))->shouldCache());
    }

    public function testModelsScopeStillInvalidatesQueryBuilderWrites(): void
    {
        config(['model-cache.scope' => 'models']);

        $this->assertTrue((new Manager($this->makeReflectorFromTables(['cars'])))->shouldInvalidate());
    }

    public function testAllScopeCachesQueryBuilderQueries(): void
    {
        config(['model-cache.scope' => 'all']);

        $this->assertTrue((new Manager($this->makeReflectorFromTables(['cars'])))->shouldCache());
    }

    public function testInvalidScopeFailsLoudly(): void
    {
        config(['model-cache.scope' => 'everything']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model-cache.scope "everything", expected one of: all, models');

        new Manager($this->makeReflectorFromTables(['cars']));
    }
}
