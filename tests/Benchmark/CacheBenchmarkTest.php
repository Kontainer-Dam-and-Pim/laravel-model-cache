<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kontainer\ModelCache\Database\ModelCacheTrait;
use Kontainer\ModelCache\Tests\Concerns\RecordsRedisCommands;
use Kontainer\ModelCache\Tests\TestCase;

final class CacheBenchmarkTest extends TestCase
{
    use RecordsRedisCommands;

    private const int CARS = 2000;

    private const int DRIVERS = 200;

    private const int READS = 2000;

    private const int DISTINCT_QUERIES = 50;

    public function testReportCachedReadThroughput(): void
    {
        $this->seedCarsAndDrivers();

        $results = array_map($this->measureShape(...), BenchmarkShape::cases());

        $this->report($results);

        foreach ($results as $result) {
            $this->assertSame(self::READS, $result['redis_commands'], $result['label'].' cached reads take one Redis command each');
        }
    }

    private function seedCarsAndDrivers(): void
    {
        DB::table('drivers')->insert(array_map(
            static fn (int $id): array => ['id' => $id, 'name' => 'driver '.$id],
            range(1, self::DRIVERS),
        ));

        foreach (array_chunk(range(1, self::CARS), 500) as $ids) {
            DB::table('cars')->insert(array_map(
                static fn (int $id): array => ['name' => 'car '.$id, 'engine_id' => null, 'driver_id' => $id % self::DRIVERS + 1],
                $ids,
            ));
        }
    }

    /**
     * @return array{shape: string, label: string, uncached_reads_per_second: float, cached_reads_per_second: float, redis_commands: int}
     */
    private function measureShape(BenchmarkShape $shape): array
    {
        config(['model-cache.active' => false]);
        $uncached = $this->readsPerSecond($shape);
        config(['model-cache.active' => true]);

        $this->readsPerSecond($shape);
        $cached = $this->readsPerSecond($shape);
        $redisCommands = count($this->recordRedisCommands(fn () => $this->readsPerSecond($shape)));

        return [
            'shape' => $shape->value,
            'label' => $shape->label(),
            'uncached_reads_per_second' => $uncached,
            'cached_reads_per_second' => $cached,
            'redis_commands' => $redisCommands,
        ];
    }

    private function readsPerSecond(BenchmarkShape $shape): float
    {
        $started = hrtime(true);

        for ($index = 0; $index < self::READS; $index++) {
            $shape->read($index % self::DISTINCT_QUERIES);
        }

        return self::READS / ((hrtime(true) - $started) / 1e9);
    }

    /**
     * @param  list<array{shape: string, label: string, uncached_reads_per_second: float, cached_reads_per_second: float, redis_commands: int}>  $results
     */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            fwrite(STDERR, sprintf(
                "\n%-28s uncached %9.0f reads/s, cached %9.0f reads/s, %5.1fx, %d Redis commands per %d cached reads",
                $result['label'],
                $result['uncached_reads_per_second'],
                $result['cached_reads_per_second'],
                $result['cached_reads_per_second'] / $result['uncached_reads_per_second'],
                $result['redis_commands'],
                self::READS,
            ));
        }

        fwrite(STDERR, "\n");

        $path = getenv('MODEL_CACHE_BENCHMARK_REPORT');

        if ($path === false || $path === '') {
            return;
        }

        $json = json_encode([
            'reads' => self::READS,
            'distinct_queries' => self::DISTINCT_QUERIES,
            'cars' => self::CARS,
            'shapes' => $results,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $temporary = $path.'.tmp';
        $this->assertNotFalse(file_put_contents($temporary, $json), 'Could not write the benchmark report to '.$temporary);
        $this->assertTrue(rename($temporary, $path), 'Could not move the benchmark report to '.$path);
    }
}

enum BenchmarkShape: string
{
    case PrimaryKey = 'primary-key';
    case LikeScan = 'like-scan';
    case JoinAggregate = 'join-aggregate';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryKey => 'Primary key lookup',
            self::LikeScan => 'LIKE scan, ordered, limited',
            self::JoinAggregate => 'Join with GROUP BY',
        };
    }

    public function read(int $variant): void
    {
        match ($this) {
            self::PrimaryKey => BenchmarkCar::query()->find($variant + 1),
            self::LikeScan => BenchmarkCar::query()->where('name', 'like', '%'.$variant)->orderBy('name')->limit(10)->get(),
            self::JoinAggregate => BenchmarkCar::query()
                ->join('drivers', 'drivers.id', '=', 'cars.driver_id')
                ->where('drivers.name', 'like', '%'.$variant)
                ->groupBy('drivers.name')
                ->orderBy('drivers.name')
                ->selectRaw('drivers.name, count(*) as cars')
                ->get(),
        };
    }
}

final class BenchmarkCar extends Model
{
    use ModelCacheTrait;

    public $timestamps = false;

    protected $table = 'cars';

    protected $fillable = ['name', 'engine_id', 'driver_id'];
}
