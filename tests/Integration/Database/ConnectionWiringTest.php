<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Database\QueryBuilder as CachingQueryBuilder;
use Kontainer\ModelCache\Tests\TestCase;

class ConnectionWiringTest extends TestCase
{
    public function testDbTableReturnsCachingQueryBuilder(): void
    {
        $builder = DB::table('cars');
        $this->assertInstanceOf(CachingQueryBuilder::class, $builder);
    }

    public function testQueryGrammarAndProcessorAreUsable(): void
    {
        $connection = DB::connection();

        $grammar = $connection->getQueryGrammar();
        $this->assertNotNull($grammar);

        $processor = $connection->getPostProcessor();
        $this->assertNotNull($processor);

        // Smoke test: compile a simple select to ensure grammar is initialized.
        $sql = $grammar->compileSelect(DB::table('cars'));
        $this->assertIsString($sql);
        $this->assertNotSame('', $sql);
    }

    public function testSchemaGrammarIsMirroredAndUsable(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $this->assertNotNull($schema, 'Expected schema builder to be initialized');

        // Smoke test: ensure hasTable() call does not throw due to null grammar
        $this->assertIsBool($schema->hasTable('cars'));
    }
}
