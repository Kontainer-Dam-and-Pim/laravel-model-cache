<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Tagger;
use Kontainer\ModelCache\Tests\TestCase;

class TagsAndKeysTest extends TestCase
{
    public function testSelectWithoutRowsHasUnspecificTag(): void
    {
        $builder = DB::table('cars');
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();

        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
    }

    public function testSelectWithRowsHasSpecificAndRowTags(): void
    {
        // Seed one car
        DB::table('cars')->insert(['id' => 1717, 'name' => 'T', 'engine_id' => null, 'driver_id' => null]);

        // Consider rows
        config(['model-cache.consider_rows' => true]);
        $builder = DB::table('cars')->where('id', 1717);
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();

        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_specific:cars", $tags);
        $this->assertNotContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_specific:cars:row:1717", $tags);
    }

    public function testTruncateHasBothSpecificAndUnspecificTags(): void
    {
        $builder = DB::table('cars');
        $reflector = new Reflector($builder, Reflector::QUERY_TYPE_TRUNCATE);
        $dbName = $reflector->getDatabase();

        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_specific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
    }
}
