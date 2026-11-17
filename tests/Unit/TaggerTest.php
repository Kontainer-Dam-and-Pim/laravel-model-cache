<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Support\AssignedColumns;
use Kontainer\ModelCache\Support\ColumnKeys;
use Kontainer\ModelCache\Support\RowWrite;
use Kontainer\ModelCache\Support\TableRead;
use Kontainer\ModelCache\Tagger;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TaggerTest extends TestCase
{
    public function testAReadPinsTheValuesItsConditionsFixEachColumnTo(): void
    {
        $reads = (new Tagger(new Reflector(DB::table('cars')->whereIn('id', [1, 2])->where('name', 'a'))))->reads();

        $this->assertEquals([new TableRead($this->database(), 'cars', ['id' => ColumnKeys::of([1, 2]), 'name' => ColumnKeys::of(['a'])])], $reads);
    }

    /**
     * @return iterable<string, array{\Closure(): Builder}>
     */
    public static function readsPinningNothing(): iterable
    {
        yield 'a range' => [static fn (): Builder => DB::table('cars')->where('id', '>', 5)];
        yield 'a pattern' => [static fn (): Builder => DB::table('cars')->where('name', 'like', 'A%')];
        yield 'no conditions' => [static fn (): Builder => DB::table('cars')];
    }

    /**
     * @param  \Closure(): Builder  $query
     */
    #[DataProvider('readsPinningNothing')]
    public function testAReadWithoutFixedValuesPinsNothing(\Closure $query): void
    {
        $this->assertEquals([new TableRead($this->database(), 'cars', [])], (new Tagger(new Reflector($query())))->reads());
    }

    public function testWithoutConsideringRowsAReadPinsNothing(): void
    {
        config(['model-cache.consider_rows' => false]);

        $this->assertEquals([new TableRead($this->database(), 'cars', [])], (new Tagger(new Reflector(DB::table('cars')->where('id', 1))))->reads());
    }

    public function testAReadOfAnAliasedTableReadsTheTable(): void
    {
        $this->assertEquals([new TableRead($this->database(), 'engines', [])], (new Tagger(new Reflector(DB::table('engines as e'))))->reads());
    }

    public function testAReadPinsOnlyTheTablesItsQualifiedConditionsName(): void
    {
        $query = DB::query()
            ->fromSub(DB::table('materials')->select('id'), 'materials')
            ->join('cars', 'cars.id', '=', 'materials.id')
            ->whereIn('cars.id', [10, 20]);

        $reads = (new Tagger(new Reflector($query)))->reads();

        $this->assertEqualsCanonicalizing([
            new TableRead($this->database(), 'materials', []),
            new TableRead($this->database(), 'cars', ['id' => ColumnKeys::of([10, 20])]),
        ], $reads);
    }

    public function testDatabaseAndTableNamesStaySeparate(): void
    {
        $this->assertEquals([new TableRead($this->database(), 'ab', [])], (new Tagger(new Reflector(DB::table('ab'))))->reads());
        $this->assertSame(['tags:database:'.$this->database().':table_unspecific:ab'], (new Tagger(new Reflector(DB::table('ab'), Reflector::QUERY_TYPE_DELETE)))->writeTags());
    }

    public function testEveryWriteReplacesTheUnspecificTagOfItsTables(): void
    {
        $tagger = new Tagger(new Reflector(DB::table('cars')->where('id', 1), Reflector::QUERY_TYPE_UPDATE, ['name' => 'x']), AssignedColumns::updated('cars', ['name' => 'x']));

        $this->assertSame(['tags:database:'.$this->database().':table_unspecific:cars'], $tagger->writeTags());
    }

    public function testAnUpdateNotAssigningAPinnedColumnChangesRowsNamedByIt(): void
    {
        $this->assertRowWrite(['id' => ColumnKeys::of([5, 10])], DB::table('cars')->whereIn('id', [5, 10]), Reflector::QUERY_TYPE_UPDATE, AssignedColumns::updated('cars', ['name' => 'x']));
    }

    public function testAnUpdateAssigningAPinnedColumnChangesRowsNamedByTheOldAndNewValues(): void
    {
        $this->assertRowWrite(['id' => ColumnKeys::of([5, 7])], DB::table('cars')->where('id', 5), Reflector::QUERY_TYPE_UPDATE, AssignedColumns::updated('cars', ['id' => 7]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function uncertainAssignments(): iterable
    {
        yield 'an expression' => [['id' => new Expression('id + 1')]];
        yield 'a qualified column' => [['cars.id' => 7]];
        yield 'a value no type keys' => [['id' => 'not a key!']];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('uncertainAssignments')]
    public function testAnUpdateAssigningAPinnedColumnUncertainlyChangesRowsItCannotName(array $values): void
    {
        $this->assertRowWrite([], DB::table('cars')->where('id', 5), Reflector::QUERY_TYPE_UPDATE, AssignedColumns::updated('cars', $values));
    }

    public function testAnUpdateOfAJoinedTableNamesNoRowsOfTablesItMayAssign(): void
    {
        $query = DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id')->where('cars.id', 1)->where('drivers.id', 2);
        $tagger = new Tagger(new Reflector($query, Reflector::QUERY_TYPE_UPDATE, ['name' => 'x']), AssignedColumns::updated('cars', ['name' => 'x']));

        $this->assertEqualsCanonicalizing([
            new RowWrite($this->database(), 'cars', ['id' => ColumnKeys::of([1])]),
            new RowWrite($this->database(), 'drivers', []),
        ], $tagger->rowWrites());
    }

    public function testADeleteChangesRowsNamedByEveryPinnedColumn(): void
    {
        $this->assertRowWrite(['id' => ColumnKeys::of([3]), 'name' => ColumnKeys::of(['a'])], DB::table('cars')->where('id', 3)->where('name', 'a'), Reflector::QUERY_TYPE_DELETE, AssignedColumns::none());
    }

    public function testAnInsertChangesRowsNamedByEveryColumnEachRowGivesAKeyedValue(): void
    {
        $assigned = AssignedColumns::inserted('cars', [['id' => 1, 'name' => 'a', 'engine_id' => null], ['id' => 2, 'name' => 'b', 'engine_id' => 3]]);

        $this->assertRowWrite(['id' => ColumnKeys::of([1, 2]), 'name' => ColumnKeys::of(['a', 'b'])], DB::table('cars'), Reflector::QUERY_TYPE_INSERT, $assigned);
    }

    public function testAnInsertOfRowsLackingAColumnNamesNoRowsByIt(): void
    {
        $this->assertRowWrite(['name' => ColumnKeys::of(['a', 'b'])], DB::table('cars'), Reflector::QUERY_TYPE_INSERT, AssignedColumns::inserted('cars', [['id' => 1, 'name' => 'a'], ['name' => 'b']]));
    }

    public function testAnInsertOfValuesThatAreNoRowsNamesNoRows(): void
    {
        $this->assertRowWrite([], DB::table('cars'), Reflector::QUERY_TYPE_INSERT, AssignedColumns::inserted('cars', [['id' => 1], 'not a row']));
    }

    public function testWritesWhoseRowsAreUnknownNameNone(): void
    {
        $this->assertRowWrite([], DB::table('cars')->where('id', 1), Reflector::QUERY_TYPE_TRUNCATE, AssignedColumns::unknown());
        $this->assertRowWrite([], DB::table('cars')->where('id', 1), Reflector::QUERY_TYPE_UPDATE, AssignedColumns::unknown());
        $this->assertRowWrite([], DB::table('cars'), Reflector::QUERY_TYPE_INSERT, AssignedColumns::unknown());
    }

    public function testWithoutConsideringRowsAWriteNamesNoRows(): void
    {
        config(['model-cache.consider_rows' => false]);

        $this->assertRowWrite([], DB::table('cars')->where('id', 1), Reflector::QUERY_TYPE_DELETE, AssignedColumns::none());
    }

    /**
     * @param  array<string, ColumnKeys>  $columns
     */
    private function assertRowWrite(array $columns, Builder $query, string $type, AssignedColumns $assigned): void
    {
        $this->assertEquals([new RowWrite($this->database(), 'cars', $columns)], (new Tagger(new Reflector($query, $type, []), $assigned))->rowWrites());
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
