<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kontainer\ModelCache\Database\ModelCacheTrait;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * Row tags follow the primary key the schema declares, whatever a model calls its key: during a
 * deploy, old and new releases of an application may disagree about it, and within one release a
 * model keyed by another unique column shares its table with builders that know no model.
 */
final class SchemaPrimaryKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('status');
        });
        DB::table('orders')->insert([['id' => 5, 'uuid' => 'u-5', 'status' => 'open'], ['id' => 6, 'uuid' => 'u-6', 'status' => 'open']]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select setval(pg_get_serial_sequence('orders', 'id'), 6)");
        }
    }

    public function testAWriteByTheKeyOfOneReleaseRefreshesAReadByTheKeyOfAnother(): void
    {
        $this->assertSame('open', OrderByUuid::query()->whereKey('u-5')->value('status'));

        OrderById::query()->whereKey(5)->update(['status' => 'paid']);

        $this->assertSame('paid', OrderByUuid::query()->whereKey('u-5')->value('status'));
    }

    public function testAWriteByTheKeyOfTheOtherReleaseRefreshesAReadByTheKeyOfTheFirst(): void
    {
        $this->assertSame('open', OrderById::query()->whereKey(5)->value('status'));

        OrderByUuid::query()->whereKey('u-5')->update(['status' => 'paid']);

        $this->assertSame('paid', OrderById::query()->whereKey(5)->value('status'));
    }

    public function testAWriteWithoutAModelRefreshesAReadOfAModelKeyedByAnotherColumn(): void
    {
        $this->assertSame('open', OrderByUuid::query()->whereKey('u-5')->value('status'));

        DB::table('orders')->where('id', 5)->update(['status' => 'paid']);

        $this->assertSame('paid', OrderByUuid::query()->whereKey('u-5')->value('status'));
    }

    public function testADeleteByAColumnThatIsNoKeyRefreshesReadsByTheKey(): void
    {
        $this->assertSame('open', DB::table('orders')->where('id', 5)->value('status'));

        DB::table('orders')->where('uuid', 'u-5')->delete();

        $this->assertNull(DB::table('orders')->where('id', 5)->value('status'));
    }

    public function testAWriteByTheKeyKeepsReadsOfOtherRowsCached(): void
    {
        $this->loadDependencyMap();
        $this->assertSame('open', OrderByUuid::query()->whereKey('u-6')->value('status'));
        $this->assertSame('open', DB::table('orders')->where('id', 6)->value('status'));
        DB::connection()->getPdo()->exec("update orders set status = 'changed behind the cache' where id = 6");

        OrderById::query()->whereKey(5)->update(['status' => 'paid']);

        $this->assertSame('open', DB::table('orders')->where('id', 6)->value('status'), 'A write pinned to the key keeps reads of other keys');

        OrderByUuid::query()->whereKey('u-5')->update(['status' => 'shipped']);

        $this->assertSame('changed behind the cache', OrderByUuid::query()->whereKey('u-6')->value('status'), 'A write pinned to another column refreshes every read pinned to rows');
    }

    public function testAWriteAssigningTheKeyRefreshesReadsOfTheOldAndTheNewKey(): void
    {
        $this->assertSame('open', DB::table('orders')->where('id', 5)->value('status'));
        $this->assertNull(DB::table('orders')->where('id', 50)->value('status'));

        OrderByUuid::query()->whereKey('u-5')->update(['id' => 50]);

        $this->assertNull(DB::table('orders')->where('id', 5)->value('status'));
        $this->assertSame('open', DB::table('orders')->where('id', 50)->value('status'));
    }

    public function testAnInsertOfAModelKeyedByAnotherColumnRefreshesAReadOfTheGeneratedKey(): void
    {
        $this->assertNull(DB::table('orders')->where('id', 7)->value('status'));

        OrderByUuid::query()->create(['uuid' => 'u-7', 'status' => 'new']);

        $this->assertSame('new', DB::table('orders')->where('id', 7)->value('status'));
    }

    public function testAnInsertReturningTheKeyRefreshesAReadOfIt(): void
    {
        $this->assertNull(OrderByUuid::query()->whereKey('u-7')->value('status'));
        $this->assertNull(DB::table('orders')->where('id', 7)->value('status'));

        DB::table('orders')->insertGetId(['uuid' => 'u-7', 'status' => 'new']);

        $this->assertSame('new', OrderByUuid::query()->whereKey('u-7')->value('status'));
        $this->assertSame('new', DB::table('orders')->where('id', 7)->value('status'));
    }

    public function testWritesNamingRowsByDifferentColumnsInOneTransactionRefreshEveryReadAtCommit(): void
    {
        $this->loadDependencyMap();
        $this->assertSame('open', DB::table('orders')->where('id', 5)->value('status'));
        $this->assertSame('open', DB::table('orders')->where('id', 6)->value('status'));

        DB::transaction(function (): void {
            OrderById::query()->whereKey(5)->update(['status' => 'paid']);
            OrderByUuid::query()->whereKey('u-6')->update(['status' => 'paid']);
        });

        $this->assertSame('paid', DB::table('orders')->where('id', 5)->value('status'));
        $this->assertSame('paid', DB::table('orders')->where('id', 6)->value('status'));
    }
}

final class OrderById extends Model
{
    use ModelCacheTrait;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;
}

final class OrderByUuid extends Model
{
    use ModelCacheTrait;

    protected $table = 'orders';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;
}
