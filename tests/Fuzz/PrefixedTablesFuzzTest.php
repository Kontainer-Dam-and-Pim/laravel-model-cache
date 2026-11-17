<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz;

use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\Fuzz\Support\Workload;
use Kontainer\ModelCache\Tests\TestCase;

final class PrefixedTablesFuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 200;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $connection = (string) $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.prefix", 'lc_');
        $app['config']->set("database.connections.{$connection}.prefix_indexes", true);
    }

    public function testCachedReadsMatchTheDatabaseWithPrefixedTables(): void
    {
        $workload = new Workload(FuzzEnvironment::seed(), self::databaseDriver());
        $workload->run(FuzzEnvironment::iterations(self::DEFAULT_ITERATIONS));

        $this->assertGreaterThan(0, $workload->comparisons());
    }
}
