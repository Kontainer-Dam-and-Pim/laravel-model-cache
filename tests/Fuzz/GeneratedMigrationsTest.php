<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Support\DependentTables;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\App\ReleaseGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Dice;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Driver;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\Schema;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaGenerator;
use Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema\SchemaWriter;
use Kontainer\ModelCache\Tests\Fuzz\Support\FuzzEnvironment;
use Kontainer\ModelCache\Tests\TestCase;

/**
 * Every schema the harness generates, and every migration between them, applies on the database,
 * and the cache reads what the database changes along with writes from each of them.
 */
final class GeneratedMigrationsTest extends TestCase
{
    private const int DEFAULT_SCHEMAS = 20;

    public function testGeneratedSchemasAndMigrationsApplyAndTheirDependentsAreRead(): void
    {
        $seed = FuzzEnvironment::seed();
        $writer = new SchemaWriter(DB::connection());
        $writer->dropEverything();

        for ($run = 0; $run < FuzzEnvironment::runs(self::DEFAULT_SCHEMAS); $run++) {
            $dice = new Dice($seed + $run);
            $generator = new SchemaGenerator($dice, Driver::of(DB::connection()->getDriverName()));
            $releases = new ReleaseGenerator($dice);
            $schemas = [$generator->initial()];
            $schemas[] = $generator->expand($schemas[0]);
            $schemas[] = $generator->expand($schemas[1]);
            $schemas[] = $releases->next($releases->initial($schemas[0]), $schemas[2])->schema;

            $current = new Schema([]);
            foreach ($schemas as $step => $schema) {
                $context = sprintf('seed %d, step %d: %s', $seed + $run, $step, json_encode($schema->toArray()));

                try {
                    $writer->migrate($current, $schema);
                    DependentTables::load(DB::connection());
                } catch (\Throwable $exception) {
                    $this->fail("{$exception->getMessage()}\n{$context}");
                }

                $current = $schema;
            }

            $writer->dropEverything();
        }

        $this->addToAssertionCount(1);
    }
}
