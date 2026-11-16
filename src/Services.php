<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

use Illuminate\Support\Facades\App;
use Kontainer\ModelCache\Debug\CacheCollector;
use LogicException;

final class Services
{
    public const string REDIS = 'model-cache.redis';

    public const string CACHE = 'model-cache.cache';

    public const string HANDLER = 'model-cache.handler';

    public const string COLLECTOR = 'model-cache.collector';

    public const string METRICS = 'model-cache.metrics';

    public static function redis(): Redis
    {
        return self::make(self::REDIS, Redis::class);
    }

    public static function cache(): Cache
    {
        return self::make(self::CACHE, Cache::class);
    }

    public static function handler(): QueryHandler
    {
        return self::make(self::HANDLER, QueryHandler::class);
    }

    public static function metrics(): Metrics
    {
        return self::make(self::METRICS, Metrics::class);
    }

    public static function collector(): ?CacheCollector
    {
        return App::bound(self::COLLECTOR) ? self::make(self::COLLECTOR, CacheCollector::class) : null;
    }

    /**
     * @template TService of object
     *
     * @param  class-string<TService>  $class
     * @return TService
     */
    public static function make(string $id, string $class): object
    {
        $service = App::make($id);

        return $service instanceof $class ? $service : throw new LogicException(sprintf('Container binding %s resolved to %s, expected %s', $id, get_debug_type($service), $class));
    }
}
