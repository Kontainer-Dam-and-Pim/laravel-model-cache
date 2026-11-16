<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Integration\Cache;

use DebugBar\DebugBar;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kontainer\ModelCache\Debug\CacheCollector;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Tests\TestCase;
use LogicException;

final class ModelCacheServiceProviderTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('model-cache.enable_debugbar', true);
        $app['config']->set('model-cache.metrics', true);
        $app->instance('debugbar', new DebugBar);
    }

    public function testDebugbarShowsCacheMissesAndHits(): void
    {
        $collector = Services::make('debugbar', DebugBar::class)->getCollector('model-cache');
        $this->assertInstanceOf(CacheCollector::class, $collector);
        $measuredBefore = count($collector->getMeasures());

        DB::table('drivers')->get();
        DB::table('drivers')->get();

        $this->assertSame(['Miss', 'Hit'], array_map(
            static fn (array $measure): string => explode(']', substr($measure['label'], 1))[0],
            array_slice($collector->getMeasures(), $measuredBefore),
        ));
    }

    public function testPruneFailureAfterMigrationsIsReportedWithoutFailingThem(): void
    {
        Log::spy();
        $this->pointCacheAtUnreachableRedis();

        event(new MigrationsEnded('up'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'pruning retired cache entries'));
    }

    public function testMetricsFailureAfterAJobIsReportedWithoutFailingIt(): void
    {
        DB::transaction(fn () => DB::table('cars')->get());
        Log::spy();
        $this->pointCacheAtUnreachableRedis();

        event(new JobProcessed('sync', $this->createStub(Job::class)));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'writing the metrics'));
    }

    public function testJobThatNeverUsedTheCacheDoesNotStartMetrics(): void
    {
        unset($this->app[Services::METRICS]);
        $this->app->singleton(Services::METRICS, static fn () => throw new LogicException('Metrics were started by a job that never used the cache'));

        event(new JobProcessed('sync', $this->createStub(Job::class)));

        $this->assertFalse($this->app->resolved(Services::METRICS));
    }
}
