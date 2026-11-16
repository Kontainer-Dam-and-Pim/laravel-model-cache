<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Console;

use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Metrics;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Tests\Concerns\RecordsRedisCommands;
use Kontainer\ModelCache\Tests\TestCase;

final class StatsCommandTest extends TestCase
{
    use RecordsRedisCommands;

    public function testSaysWhenMetricsAreOff(): void
    {
        $this->artisan('model-cache:stats')
            ->expectsOutput('Model Cache metrics are off. Set MODEL_CACHE_METRICS=true to collect them.')
            ->assertExitCode(0);
    }

    public function testRejectsHoursBelowOne(): void
    {
        config(['model-cache.metrics' => true]);
        $this->app->forgetInstance(Services::METRICS);

        $this->artisan('model-cache:stats', ['--hours' => '0'])
            ->expectsOutput('--hours must be a whole number of 1 or more.')
            ->assertExitCode(1);
    }

    public function testNothingIsCountedOrWrittenWhileMetricsAreOff(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->get();
        DB::table('cars')->whereRaw('current_timestamp is not null')->get();

        $hit = $this->recordRedisCommands(fn () => DB::table('cars')->get());
        $requestEnd = $this->recordRedisCommands(fn () => $this->app->terminate());
        $redis = Services::redis();

        $this->assertSame(['evalsha'], $hit);
        $this->assertSame([], $requestEnd);
        $this->assertSame([], $redis->getConnection()->command('keys', [$redis->prefix(Metrics::BUCKET_PREFIX.'*')]));
    }
}
