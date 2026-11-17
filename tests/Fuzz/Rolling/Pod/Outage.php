<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\ExpirationPolicy;
use Kontainer\ModelCache\QueryHandler;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Support\FailedInvalidations;
use Kontainer\ModelCache\Support\RedisAvailability;
use Kontainer\ModelCache\Support\RedisCooldown;
use LogicException;

/**
 * A pod's Redis outage: while it lasts, the pod's queries run through services whose Redis
 * cannot be reached, recording the invalidations Redis rejects in the APCu store the pod's own
 * services replay them from once Redis answers again, as PHP-FPM workers share them. When it
 * ends the pod replays them, as the request in flight does when it ends; until a host replays,
 * other hosts serve what they cached of the tables it wrote, which the README documents.
 */
final class Outage
{
    private const string UNREACHABLE_CONNECTION = 'unreachable';

    private ?QueryHandler $healthy = null;

    public function begin(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            throw new LogicException('Redis outages need APCu with apc.enable_cli=1, through which a pod replays the invalidations Redis rejected');
        }

        $this->healthy = Services::handler();
        $failed = FailedInvalidations::detect(Services::redis()->processStateKey('failed'), ExpirationPolicy::fromConfig()->seconds);
        $unreachable = new Cache(self::unreachableRedis(), new Encoder);
        app()->instance(Services::HANDLER, new QueryHandler($unreachable, $failed));
    }

    /**
     * A Redis client of the unreachable connection that connects on first use, as the pod's own
     * does, so the failure to connect reaches the cache where Redis failures do.
     */
    private static function unreachableRedis(): Redis
    {
        $configured = config('model-cache.redis_connection');
        config(['model-cache.redis_connection' => self::UNREACHABLE_CONNECTION]);

        try {
            return new Redis(null, RedisAvailability::processLocal());
        } finally {
            config(['model-cache.redis_connection' => $configured]);
        }
    }

    public function end(): void
    {
        app()->instance(Services::HANDLER, $this->healthy ?? throw new LogicException('Redis was not lost'));
        $this->healthy = null;

        if (! Services::handler()->replayFailedInvalidations(RedisCooldown::Ignore)) {
            throw new LogicException('Redis rejected the invalidations it missed after it came back');
        }
    }
}
