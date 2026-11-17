<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod\Server;

/**
 * Runs plans of deploys from a test case: the pods share the test's database, which on SQLite is
 * a file they can all open, and its Redis.
 */
trait RunsDeploys
{
    private ?string $sqliteFile = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (self::databaseDriver() !== 'sqlite') {
            return;
        }

        $this->sqliteFile = (string) tempnam(sys_get_temp_dir(), 'model-cache-deploy-');
        $app['config']->set('database.connections.testing.database', $this->sqliteFile);
        $app['config']->set('database.connections.testing.journal_mode', 'wal');
        $app['config']->set('database.connections.testing.busy_timeout', Server::LOCK_WAIT_SECONDS * 1000);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->sqliteFile !== null && is_file($this->sqliteFile.$suffix)) {
                unlink($this->sqliteFile.$suffix);
            }
        }
    }

    /**
     * A coordinator of the plan, its pods configured as the test's application is, writing every
     * step to the file `MODEL_CACHE_FUZZ_TRACE` names.
     */
    private function coordinator(Plan $plan): Coordinator
    {
        $default = (string) config('database.default');

        return new Coordinator($plan, [
            'database.default' => $default,
            "database.connections.{$default}" => config("database.connections.{$default}"),
            'database.redis' => config('database.redis'),
            'model-cache' => config('model-cache'),
        ], getenv('MODEL_CACHE_FUZZ_TRACE') ?: null);
    }

    private static function readPlan(string $file): Plan
    {
        return Plan::fromArray(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Writes the plan to `build/fuzz`, atomically, and returns the file.
     */
    private function writeFailingPlan(Plan $plan, string $kind): string
    {
        $directory = dirname(__DIR__, 3).'/build/fuzz';

        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}");
        }

        $file = sprintf('%s/%s-%s-%d.json', $directory, $kind, self::databaseDriver(), $plan->seed);
        $temporary = $file.'.tmp';
        file_put_contents($temporary, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($temporary, $file);

        return $file;
    }
}
