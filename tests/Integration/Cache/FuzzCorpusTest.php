<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\RunsDeploys;
use Kontainer\ModelCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Replays the deploys in `tests/Fuzz/corpus`: plans of stale reads fuzzing found, shrunk and kept
 * so the bugs they found stay fixed on every database.
 */
final class FuzzCorpusTest extends TestCase
{
    use RunsDeploys;

    /**
     * @return iterable<string, array{string}>
     */
    public static function plans(): iterable
    {
        $files = glob(dirname(__DIR__, 2).'/Fuzz/corpus/*.json') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[DataProvider('plans')]
    public function testEveryReadOfTheDeployMatchesTheDatabase(string $file): void
    {
        $statistics = $this->coordinator(self::readPlan($file))->run();

        $this->assertGreaterThan(0, $statistics->reads);
    }
}
