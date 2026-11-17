<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Tests\Concerns\CacheAssertions;
use Kontainer\ModelCache\Tests\Database\Models\License;
use Kontainer\ModelCache\Tests\TestCase;

final class PrimaryKeyScopeTest extends TestCase
{
    use CacheAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        License::query()->create(['code' => '1001', 'holder' => 'holder']);
        DB::table('license_renewals')->insert(['id' => 1, 'code' => '1001', 'note' => 'before']);
        $this->loadDependencyMap();
    }

    public function testJoinedTableFilteredByTheModelKeyColumnRefreshesAfterWriteByItsOwnKey(): void
    {
        $this->assertSame(['before'], $this->renewalNotesOf('1001'));

        DB::table('license_renewals')->where('id', 1)->update(['note' => 'after']);

        $this->assertSame(['after'], $this->renewalNotesOf('1001'));
    }

    public function testEachTablePinsRowsOfItsOwnPrimaryKeyOnly(): void
    {
        $tags = $this->resolvedReadTags(License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('licenses.code', '1001')
            ->where('license_renewals.code', '1001')
            ->getQuery());

        $this->assertContains($this->tablePrefix().'table_specific:licenses:row:1001', $tags);
        $this->assertContains($this->tablePrefix().'table_unspecific:license_renewals', $tags);
    }

    public function testRowsOfAJoinedTableArePinnedByItsPrimaryKeyFromTheSchema(): void
    {
        $tags = $this->resolvedReadTags(License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('license_renewals.id', 1)
            ->getQuery());

        $this->assertContains($this->tablePrefix().'table_specific:license_renewals:row:1', $tags);
    }

    public function testRowsAreNotPinnedByAColumnAModelCallsItsKeyThatTheSchemaDoesNot(): void
    {
        $tags = $this->resolvedReadTags(DB::table('license_renewals')->where('code', '1001'));

        $this->assertSame([$this->tablePrefix().'table_unspecific:license_renewals'], $tags);
    }

    private function tablePrefix(): string
    {
        return 'tags:database:'.DB::connection()->getDatabaseName().':';
    }

    /**
     * @return list<string>
     */
    private function renewalNotesOf(string $code): array
    {
        return License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('license_renewals.code', $code)
            ->pluck('license_renewals.note')
            ->all();
    }
}
