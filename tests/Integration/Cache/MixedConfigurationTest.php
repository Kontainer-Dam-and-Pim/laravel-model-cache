<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * Hosts sharing one Redis may run with different settings, such as old and new pods while a
 * configuration change rolls out; each one's writes must still invalidate the others' reads.
 */
final class MixedConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
    }

    public function testWriteFromHostIgnoringRowsInvalidatesRowReadOfHostConsideringRows(): void
    {
        config(['model-cache.consider_rows' => true]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        config(['model-cache.consider_rows' => false]);
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);

        config(['model-cache.consider_rows' => true]);
        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testRowWriteFromHostConsideringRowsInvalidatesReadOfHostIgnoringRows(): void
    {
        config(['model-cache.consider_rows' => false]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        config(['model-cache.consider_rows' => true]);
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);

        config(['model-cache.consider_rows' => false]);
        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testInsertFromHostIgnoringRowsInvalidatesMissingRowReadOfHostConsideringRows(): void
    {
        config(['model-cache.consider_rows' => true]);
        $this->assertNull(DB::table('cars')->where('id', 2)->value('name'));

        config(['model-cache.consider_rows' => false]);
        DB::table('cars')->insert(['id' => 2, 'name' => 'created', 'engine_id' => null, 'driver_id' => null]);

        config(['model-cache.consider_rows' => true]);
        $this->assertSame('created', DB::table('cars')->where('id', 2)->value('name'));
    }
}
