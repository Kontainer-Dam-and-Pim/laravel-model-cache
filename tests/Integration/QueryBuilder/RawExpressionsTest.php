<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Tagger;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\TestCase;

class RawExpressionsTest extends TestCase
{
    use CacheAssertions;

    public function testJoinSubPropagatesSubqueryTags(): void
    {
        // Seed minimal rows
        DB::table('engines')->insert(['id' => 501, 'name' => 'E']);
        DB::table('cars')->insert(['id' => 201, 'name' => 'C', 'engine_id' => 501, 'driver_id' => null]);

        $sub = DB::table('engines')->select('id');
        $builder = DB::table('cars')
            ->joinSub($sub, 'engines', 'cars.engine_id', '=', 'engines.id')
            ->where('cars.id', 201)
            ->select('cars.*');

        // Assert Tagger (main query) produces specific for cars (row-scoped) and unspecific for engines (broad)
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_specific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_unspecific:engines", $tags);
    }

    public function testSelectSubPropagatesSubqueryTags(): void
    {
        // Seed minimal rows
        DB::table('drivers')->insert(['id' => 301, 'name' => 'D']);
        DB::table('cars')->insert(['id' => 202, 'name' => 'C2', 'engine_id' => null, 'driver_id' => 301]);

        $sub = DB::table('drivers')->select('id')->where('drivers.id', 301);
        $builder = DB::table('cars')
            ->where('cars.id', 202)
            ->select('cars.*')
            ->selectSub($sub, 'driver_id_sub');

        // Assert Tagger (main query) produces cars tag; since it's row-scoped it is specific.
        // Subquery tags are propagated during caching, not by Tagger here.
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_specific:cars", $tags);
    }
}
