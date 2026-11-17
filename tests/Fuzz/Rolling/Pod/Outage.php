<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Pod;

use Illuminate\Support\Facades\Redis as RedisFacade;
use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\Encoder;
use Kontainer\ModelCache\ExpirationPolicy;
use Kontainer\ModelCache\QueryHandler;
use Kontainer\ModelCache\Redis;
use Kontainer\ModelCache\Services;
use Kontainer\ModelCache\Support\FailedInvalidations;
use Kontainer\ModelCache\Support\RedisAvailability;
use LogicException;

/**
 * A pod's Redis outage: while it lasts, the pod's queries run through services whose Redis
 * cannot be reached, recording the invalidations Redis rejects in the APCu store the pod's own
 * services replay them from once Redis answers again, as PHP-FPM workers share them.
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
        $unreachable = new Cache(new Redis(RedisFacade::connection(self::UNREACHABLE_CONNECTION), RedisAvailability::processLocal()), new Encoder);
        app()->instance(Services::HANDLER, new QueryHandler($unreachable, $failed));
    }

    public function end(): void
    {
        app()->instance(Services::HANDLER, $this->healthy ?? throw new LogicException('Redis was not lost'));
        $this->healthy = null;
    }
}
