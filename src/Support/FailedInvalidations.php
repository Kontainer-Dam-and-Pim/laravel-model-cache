<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

use APCUIterator;
use Closure;

/**
 * Invalidations that could not reach Redis, kept until Redis accepts them again.
 *
 * They are always kept by the current object, which lives for the whole worker in FrankenPHP
 * worker mode or Octane. When APCu is available they are also kept in APCu, so the next request
 * of a PHP-FPM pool replays them even though every request starts fresh. A backlog larger than
 * MAX_PENDING collapses into retiring the whole cache, which bounds memory during long outages.
 */
final class FailedInvalidations
{
    public const int MAX_PENDING = 1000;

    private Invalidation $local;

    private function __construct(
        private readonly string $sharedPrefix,
        private readonly bool $shared,
        private readonly int $ttlSeconds,
    ) {
        $this->local = Invalidation::none();
    }

    public static function detect(string $sharedPrefix, int $ttlSeconds): self
    {
        return new self($sharedPrefix, function_exists('apcu_enabled') && apcu_enabled(), $ttlSeconds);
    }

    public static function processLocal(): self
    {
        return new self('', false, 0);
    }

    public function record(Invalidation $invalidation): void
    {
        if ($invalidation->isEmpty()) {
            return;
        }

        $this->local = self::bounded($this->local->merge($invalidation));

        if ($this->shared) {
            $this->recordShared($invalidation);
        }
    }

    public function hasPending(): bool
    {
        if (! $this->local->isEmpty()) {
            return true;
        }

        return $this->shared && $this->sharedCount() > 0;
    }

    /**
     * Hands every pending invalidation to the replay as one merged invalidation and forgets
     * them once it returns. A throwing replay keeps them pending.
     *
     * @param  Closure(Invalidation): void  $replay
     */
    public function replay(Closure $replay): void
    {
        $counter = $this->shared ? $this->sharedCount() : 0;
        $sharedKeys = [];
        $merged = $this->local;

        if ($this->shared) {
            if (apcu_exists($this->everythingKey())) {
                $merged = Invalidation::everything();
                $sharedKeys[] = $this->everythingKey();
            }

            foreach (new APCUIterator('/^'.preg_quote($this->itemPrefix(), '/').'/', APC_ITER_VALUE) as $key => $item) {
                $value = is_array($item) ? $item['value'] ?? null : null;
                $invalidation = is_string($value) ? @unserialize($value, ['allowed_classes' => [Invalidation::class]]) : null;
                $merged = self::bounded($merged->merge($invalidation instanceof Invalidation ? $invalidation : Invalidation::everything()));
                $sharedKeys[] = $key;
            }
        }

        if (! $merged->isEmpty()) {
            $replay($merged);
        }

        $this->local = Invalidation::none();

        if ($this->shared) {
            apcu_delete($sharedKeys);
            apcu_cas($this->counterKey(), $counter, 0);
        }
    }

    private function recordShared(Invalidation $invalidation): void
    {
        apcu_add($this->counterKey(), 0);
        $pending = (int) apcu_inc($this->counterKey());

        $itemStored = $invalidation->retires === null
            && $pending <= self::MAX_PENDING
            && apcu_store($this->itemPrefix().bin2hex(random_bytes(8)), serialize($invalidation), $this->ttlSeconds);

        if (! $itemStored) {
            apcu_store($this->everythingKey(), true, $this->ttlSeconds);
        }
    }

    private static function bounded(Invalidation $invalidation): Invalidation
    {
        return $invalidation->retires !== null || $invalidation->size() > self::MAX_PENDING ? Invalidation::everything($invalidation->retires ?? RetireReason::Fallback) : $invalidation;
    }

    private function sharedCount(): int
    {
        $count = apcu_fetch($this->counterKey());

        return is_int($count) ? $count : 0;
    }

    private function counterKey(): string
    {
        return $this->sharedPrefix.'count';
    }

    private function itemPrefix(): string
    {
        return $this->sharedPrefix.'item:';
    }

    private function everythingKey(): string
    {
        return $this->sharedPrefix.'everything';
    }
}
