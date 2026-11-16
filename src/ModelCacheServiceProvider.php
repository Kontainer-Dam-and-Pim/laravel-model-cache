<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

use DebugBar\DebugBar;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Kontainer\ModelCache\Console\DisableCommand;
use Kontainer\ModelCache\Console\EnableCommand;
use Kontainer\ModelCache\Console\FlushCommand;
use Kontainer\ModelCache\Console\InvalidateCommand;
use Kontainer\ModelCache\Console\StatsCommand;
use Kontainer\ModelCache\Database\MariaDbConnection as CachingMariaDbConnection;
use Kontainer\ModelCache\Database\MySqlConnection as CachingMySqlConnection;
use Kontainer\ModelCache\Database\PostgresConnection as CachingPostgresConnection;
use Kontainer\ModelCache\Database\QueryBuilder;
use Kontainer\ModelCache\Database\SqliteConnection as CachingSqliteConnection;
use Kontainer\ModelCache\Database\SqlServerConnection as CachingSqlServerConnection;
use Kontainer\ModelCache\Debug\CacheCollector;
use Kontainer\ModelCache\Support\CacheOperation;
use Kontainer\ModelCache\Support\ConfigValue;
use Kontainer\ModelCache\Support\DependentTables;
use Kontainer\ModelCache\Support\FailedInvalidations;
use Kontainer\ModelCache\Support\FailureReporter;
use Kontainer\ModelCache\Support\FrameworkTables;
use Kontainer\ModelCache\Support\RedisCooldown;
use Kontainer\ModelCache\Support\ReplicaReads;
use Kontainer\ModelCache\Support\RetireReason;
use LogicException;
use Throwable;

/**
 * Model Cache service provider for Laravel.
 *
 * Registers bindings, database connection integration (via DB::extend()),
 * artisan commands, and optional Debugbar integration. Configuration
 * publishing is provided and the package's config is merged during registration.
 */
final class ModelCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_FILE = 'model-cache.php';

    private const array SINGLETONS = [Services::REDIS, Services::METRICS, Services::CACHE, Services::HANDLER];

    private const array CACHING_CONNECTIONS = [
        'mysql' => CachingMySqlConnection::class,
        'mariadb' => CachingMariaDbConnection::class,
        'pgsql' => CachingPostgresConnection::class,
        'sqlite' => CachingSqliteConnection::class,
        'sqlsrv' => CachingSqlServerConnection::class,
    ];

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/'.self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ], 'config');

        // Propagate withoutCache() from the Eloquent builder to the underlying caching query builder.
        // Registered outside the active guard so the call is a no-op when the cache is disabled.
        EloquentBuilder::macro('withoutCache', function () {
            /** @var EloquentBuilder<Model> $this */
            $query = $this->getQuery();

            if ($query instanceof QueryBuilder) {
                $query->withoutCache();
            }

            return $this;
        });

        // If Model Cache is not active, avoid wiring listeners / debugbar that could resolve Redis.
        if (! (bool) config('model-cache.active', true)) {
            return;
        }

        $this->warmSingletonsUnderOctane();

        config(['model-cache.exclude_tables' => array_values(array_unique([
            ...ConfigValue::strings('model-cache.exclude_tables'),
            ...FrameworkTables::volatile(),
        ]))]);

        if (config('model-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }

        $events = $this->app->make(Dispatcher::class);
        $events->listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            Services::handler()->transactionCommitted($event->connection);
        });
        $events->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            Services::handler()->transactionRolledBack($event->connection);
        });

        $events->listen(MigrationEnded::class, function (MigrationEnded $event): void {
            Services::handler()->schemaChanged($this->app->make(Migrator::class)->resolveConnection($event->migration->getConnection()), RetireReason::Migration);
        });
        $events->listen([SchemaLoaded::class, DatabaseRefreshed::class], static function (SchemaLoaded|DatabaseRefreshed $event): void {
            Services::handler()->schemaChanged(
                $event instanceof SchemaLoaded ? $event->connection : DB::connection($event->database)
            );
        });
        $events->listen(MigrationsEnded::class, static function (): void {
            try {
                Services::cache()->pruneRetiredGenerations();
            } catch (Throwable $exception) {
                FailureReporter::report(CacheOperation::Prune, $exception);
            }
        });

        $this->app->terminating(function (): void {
            if ($this->app->resolved(Services::HANDLER)) {
                Services::handler()->replayFailedInvalidations(RedisCooldown::Ignore);
            }

            $this->writeCountedMetrics();
        });
        $events->listen([JobProcessed::class, JobFailed::class], fn () => $this->writeCountedMetrics());
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/'.self::CONFIG_FILE,
            'model-cache'
        );

        // Only register when active to avoid bootstrap overhead when disabled
        if ((bool) config('model-cache.active', true) && $this->hasValidConfiguration()) {
            $this->registerSingletons();
            $this->registerDatabaseDecorator();
        }

        $this->registerCommands();
    }

    /**
     * Writes the counters only PHP sees, once per request or job rather than as they happen.
     */
    private function writeCountedMetrics(): void
    {
        if (! $this->app->resolved(Services::METRICS)) {
            return;
        }

        try {
            Services::metrics()->flush(Services::redis());
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Count, $exception);
        }
    }

    private function hasValidConfiguration(): bool
    {
        try {
            CacheScope::fromConfig();
            ConfigValue::strings('model-cache.include_tables');
            ConfigValue::strings('model-cache.exclude_tables');
            ConfigValue::string('model-cache.prefix', Redis::DEFAULT_PREFIX);
            ConfigValue::string('model-cache.redis_connection', Redis::DEFAULT_CONNECTION);
            ExpirationPolicy::fromConfig();
            EntrySizeLimit::fromConfig();
            ReplicaReads::configuredLagSeconds();
            Metrics::fromConfig();
            DependentTables::configured();

            return true;
        } catch (InvalidArgumentException $exception) {
            Log::error('[ModelCache] Disabled because of invalid configuration: '.$exception->getMessage());
            config(['model-cache.active' => false]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return self::SINGLETONS;
    }

    private function registerSingletons(): void
    {
        $this->app->singleton(Services::REDIS, static fn (): Redis => new Redis);
        $this->app->singleton(Services::METRICS, static fn (): Metrics => Metrics::fromConfig());
        $this->app->singleton(Services::CACHE, static fn (): Cache => new Cache(Services::redis(), new Encoder, metrics: Services::metrics()));
        $this->app->singleton(Services::HANDLER, static fn (): QueryHandler => new QueryHandler(
            Services::cache(),
            FailedInvalidations::detect(Services::redis()->processStateKey('failed'), ExpirationPolicy::fromConfig()->seconds),
            Services::metrics(),
        ));
    }

    /**
     * Copy driver-specific state from the base connection to the caching connection.
     */
    private static function hydrateCachingConnection(Connection $base, Connection $caching): Connection
    {
        $caching->setReadPdo($base->getRawReadPdo());
        $caching->setQueryGrammar($base->getQueryGrammar());
        $caching->setPostProcessor($base->getPostProcessor());

        $base->getSchemaBuilder();
        $caching->setSchemaGrammar($base->getSchemaGrammar());

        return $caching;
    }

    /**
     * Octane, which also runs FrankenPHP worker mode, resolves services in a per-request copy of
     * the container unless they are warmed. Warming keeps the failure cooldown and invalidations
     * awaiting replay alive across requests, as they are meant to be in long-lived workers.
     */
    private function warmSingletonsUnderOctane(): void
    {
        if (! is_array(config('octane.warm'))) {
            return;
        }

        config(['octane.warm' => array_values(array_unique([...ConfigValue::strings('octane.warm'), ...self::SINGLETONS]))]);
    }

    private function registerDatabaseDecorator(): void
    {
        foreach (self::CACHING_CONNECTIONS as $driver => $class) {
            DB::extend($driver, static fn (array $config, string $name): Connection => self::cachingConnection($class, $config, $name));
        }
    }

    /**
     * @param  class-string<CachingMySqlConnection|CachingMariaDbConnection|CachingPostgresConnection|CachingSqliteConnection|CachingSqlServerConnection>  $class
     * @param  array<mixed>  $config
     */
    private static function cachingConnection(string $class, array $config, string $name): Connection
    {
        $base = Services::make('db.factory', ConnectionFactory::class)->make($config, $name);
        $baseConfig = $base->getConfig();

        return self::hydrateCachingConnection($base, new $class(
            $base->getRawPdo() ?? throw new LogicException("Database connection {$name} has no PDO to share with Model Cache"),
            $base->getDatabaseName(),
            $base->getTablePrefix(),
            is_array($baseConfig) ? $baseConfig : throw new LogicException("Database connection {$name} has no configuration array"),
        ));
    }

    private function registerCommands(): void
    {
        $this->app->singleton('command.model-cache.flush', static fn () => new FlushCommand);
        $this->app->singleton('command.model-cache.enable', static fn () => new EnableCommand);
        $this->app->singleton('command.model-cache.disable', static fn () => new DisableCommand);
        $this->app->singleton('command.model-cache.invalidate', static fn () => new InvalidateCommand);
        $this->app->singleton('command.model-cache.stats', static fn () => new StatsCommand);

        $this->commands([
            'command.model-cache.flush',
            'command.model-cache.enable',
            'command.model-cache.disable',
            'command.model-cache.invalidate',
            'command.model-cache.stats',
        ]);
    }

    private function registerDebugbarCollector(): void
    {
        $this->app->singleton(Services::COLLECTOR, static fn (): CacheCollector => new CacheCollector);
        Services::make('debugbar', DebugBar::class)->addCollector(Services::collector() ?? throw new LogicException('Model Cache collector was not registered'));
    }
}
