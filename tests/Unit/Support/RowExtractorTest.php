<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Support\RowExtractor;
use Kontainer\ModelCache\Tests\TestCase;

class RowExtractorTest extends TestCase
{
    public function testOrOfOneColumnPinsTheUnionOfItsValues(): void
    {
        $b = DB::table('cars')
            ->where('id', '=', 3)
            ->orWhereIn('id', [7, 9]);

        $this->assertSame(['cars' => ['id' => [3, 7, 9]]], RowExtractor::extractColumnPins($b));
    }

    public function testIntegerInRawWheresPin(): void
    {
        $b = DB::table('cars')->whereIntegerInRaw('id', [3, '5']);

        $this->assertSame('InRaw', $b->wheres[0]['type']);
        $this->assertSame(['cars' => ['id' => [3, 5]]], RowExtractor::extractColumnPins($b));
    }

    public function testOrWithABranchOnAnotherColumnPinsNothing(): void
    {
        $b = DB::table('cars')->where('id', '=', 3)->orWhere('name', '=', 'x');

        $this->assertSame([], RowExtractor::extractColumnPins($b));
    }

    public function testUpperCaseOrIsAnOr(): void
    {
        $b = DB::table('cars')->where('id', '=', 3)->where('name', '=', 'x', 'OR');

        $this->assertSame([], RowExtractor::extractColumnPins($b));
    }

    public function testOrAcrossTablesPinsNothing(): void
    {
        $b = DB::table('cars')
            ->join('drivers', 'drivers.id', '=', 'cars.driver_id')
            ->where('cars.id', '=', 3)
            ->orWhere('drivers.id', '=', 5);

        $this->assertSame([], RowExtractor::extractColumnPins($b));
    }

    public function testOrWithAConditionThatPinsNothingPinsNothing(): void
    {
        $b = DB::table('cars')->where('id', 3)->orWhere('id', '>', 5);

        $this->assertSame([], RowExtractor::extractColumnPins($b));
    }

    public function testNegatedConditionsPinNothing(): void
    {
        $b = DB::table('cars')->where('id', '=', 4)->whereNot('id', 3);

        $this->assertSame(['cars' => ['id' => [4]]], RowExtractor::extractColumnPins($b));
    }

    public function testAliasesResolveToTheirTable(): void
    {
        $b = DB::table('cars as c')->where('c.id', '=', 11);

        $this->assertSame(['cars' => ['id' => [11]]], RowExtractor::extractColumnPins($b));
    }

    public function testEveryColumnTheConditionsFixPins(): void
    {
        $b = DB::table('cars')->where('name', '=', 'x')->where('ID', 2);

        $this->assertSame(['cars' => ['name' => ['x'], 'id' => [2]]], RowExtractor::extractColumnPins($b));
    }

    public function testColumnPinsOfJoinedQueriesNeedQualifiedColumns(): void
    {
        $b = DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id')->where('name', 'x')->where('cars.driver_id', 3);

        $this->assertSame(['cars' => ['driver_id' => [3]]], RowExtractor::extractColumnPins($b));
    }

    public function testTablesReadMoreThanOncePinNothing(): void
    {
        $b = DB::table('cars')->where('cars.id', 3);

        $this->assertSame([], RowExtractor::extractColumnPins($b, ['cars' => 2]));
    }

    public function testWheresOnNumericExpressionsPinNothing(): void
    {
        $b = DB::table('cars')->where(new Expression(1), '=', 1);

        $this->assertSame([], RowExtractor::extractColumnPins($b));
    }
}
