<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Support\TableExtractor;
use Kontainer\ModelCache\Tests\TestCase;
use UnexpectedValueException;

class TableExtractorTest extends TestCase
{
    public function testExtractTablesFromSimpleFromJoinAndUnion(): void
    {
        $b1 = DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id');
        $b2 = DB::table('engines as e');
        $b = $b1->union($b2);

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('cars', $tables);
        $this->assertContains('drivers', $tables);
        $this->assertContains('engines', $tables);
    }

    public function testExtractTablesFromExpressionFromAndJoinSub(): void
    {
        $sub = DB::table('materials')->select('id');
        $b = DB::query()->fromSub($sub, 'materials')->joinSub(DB::table('cars'), 'cars', 'materials.id', '=', 'cars.id');

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('materials', $tables);
        $this->assertContains('cars', $tables);
    }

    public function testExtractTablesFromFromSubWithAliasThatIsNotATable(): void
    {
        $sub = DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id')->select('cars.id');
        $b = DB::query()->fromSub(DB::query()->fromSub($sub, 'inner_cars'), 'outer_cars');

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('cars', $tables);
        $this->assertContains('drivers', $tables);
    }

    public function testExtractTablesFromExistsSubquery(): void
    {
        $b = DB::table('cars')->whereExists(function ($q): void {
            $q->from('materials')->whereColumn('materials.id', 'cars.id');
        });

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('cars', $tables);
        $this->assertContains('materials', $tables);
    }

    public function testJoinsThatAreNoJoinClausesFailLoudly(): void
    {
        $b = DB::table('cars');
        $b->joins = ['drivers'];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Model Cache reads joins of the query builder as join clauses, got string');

        TableExtractor::extractTables($b);
    }
}
