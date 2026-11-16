<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Database;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\TestCase;

final class QueryBuilderTest extends TestCase
{
    public function testLockedExistsReadsTheDatabaseInsteadOfTheCache(): void
    {
        $this->assertFalse(DB::table('cars')->exists());
        $this->insertCarBehindTheCache(1, 'unseen');

        $this->assertFalse(DB::table('cars')->exists());
        $this->assertTrue(DB::table('cars')->lockForUpdate()->exists());
    }

    public function testInsertOrIgnoreUsingInvalidatesCachedReadsOfTheTarget(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'copied']);
        $this->assertSame(0, DB::table('drivers')->count());

        DB::table('drivers')->insertOrIgnoreUsing(['name'], DB::table('cars')->select('name'));

        $this->assertSame(['copied'], DB::table('drivers')->pluck('name')->all());
    }

    public function testUpdateFromInvalidatesCachedReadsOfTheTarget(): void
    {
        $this->requireDatabaseDriver('pgsql');
        DB::table('drivers')->insert(['id' => 1, 'name' => 'driver']);
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'driver_id' => 1]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id')->updateFrom(['name' => new Expression('drivers.name')]);

        $this->assertSame('driver', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testUpsertWithoutUpdateColumnsInvalidatesCachedReadsOfEveryUpsertedColumn(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before']);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::table('cars')->upsert([['id' => 1, 'name' => 'after']], ['id']);

        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testUpdateOfARawTableInvalidatesCachedReadsOfIt(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before']);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::table(new Expression('cars'))->where('id', 1)->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testInsertIntoARawTableInvalidatesCachedReadsOfIt(): void
    {
        $this->assertSame(0, DB::table('cars')->count());

        DB::table(new Expression('cars'))->insert(['id' => 1, 'name' => 'inserted']);

        $this->assertSame(1, DB::table('cars')->count());
    }

    private function insertCarBehindTheCache(int $id, string $name): void
    {
        $statement = DB::connection()->getPdo()->prepare('insert into cars (id, name) values (?, ?)');
        $statement->execute([$id, $name]);
    }
}
