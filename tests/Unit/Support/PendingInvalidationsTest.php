<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support;

use Illuminate\Database\SQLiteConnection;
use Kontainer\ModelCache\Support\Invalidation;
use Kontainer\ModelCache\Support\PendingInvalidations;
use Kontainer\ModelCache\Support\Write;
use PDO;
use PHPUnit\Framework\TestCase;

final class PendingInvalidationsTest extends TestCase
{
    public function testStateBelongsToTheConnectionObjectNotItsName(): void
    {
        $pending = new PendingInvalidations;
        $purged = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);
        $reconnected = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);

        $pending->add($purged, Invalidation::of(['a', 'b']));
        $pending->add($purged, Invalidation::of(['a'])->withChangesOf('db', 'sqlite', Write::delete(), ['cars']));
        $pending->add($purged, Invalidation::everything());

        $this->assertTrue($pending->take($reconnected)->isEmpty());
        $taken = $pending->take($purged);
        $this->assertSame(['a', 'b'], $taken->tags());
        $this->assertSame(['db' => ['a:cars', 'd:cars', 'gd:cars']], $taken->cascadeSources());
        $this->assertNotNull($taken->retires);
        $this->assertTrue($pending->take($purged)->isEmpty());
    }
}
