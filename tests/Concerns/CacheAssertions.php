<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Concerns;

use Illuminate\Database\Query\Builder;
use Kontainer\ModelCache\Cache;
use Kontainer\ModelCache\CacheHit;
use Kontainer\ModelCache\CacheMiss;
use Kontainer\ModelCache\Database\QueryBuilder;
use Kontainer\ModelCache\Hasher;
use Kontainer\ModelCache\Reflector;
use Kontainer\ModelCache\Tagger;
use PHPUnit\Framework\Assert;

trait CacheAssertions
{
    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function seedCache(string $key, array|ReadTags $tags, array $rows): void
    {
        $lookup = $this->lookupRead($key, $tags);
        Assert::assertInstanceOf(CacheMiss::class, $lookup, "Expected no valid entry for '{$key}' before seeding");
        $this->modelCache()->store($lookup, $rows);
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function assertCacheHas(string $key, array|ReadTags $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheHit::class,
            $this->lookupRead($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has a valid entry for '{$key}'"
        );
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function assertCacheMissing(string $key, array|ReadTags $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheMiss::class,
            $this->lookupRead($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has no valid entry for '{$key}'"
        );
    }

    /**
     * @return array{0: string, 1: ReadTags}
     */
    protected function cacheKeyAndTags(Builder $builder): array
    {
        Assert::assertInstanceOf(QueryBuilder::class, $builder);
        $reflector = new Reflector($builder);
        $tagger = new Tagger($reflector);

        return [(new Hasher($reflector))->getHash(), new ReadTags([], $tagger->reads())];
    }

    /**
     * The tags a lookup of the read depends on besides its cascade tags, as the lookup script
     * tags its tables.
     *
     * @return list<string>
     */
    protected function resolvedReadTags(Builder $builder): array
    {
        [, $read] = $this->cacheKeyAndTags($builder);
        $lookup = $this->lookupRead('resolved-read-tags', $read);
        Assert::assertInstanceOf(CacheMiss::class, $lookup, 'Nothing is ever stored under the key that resolves tags');

        return $lookup->tags;
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function lookupRead(string $key, array|ReadTags $tags): CacheHit|CacheMiss
    {
        $read = $tags instanceof ReadTags ? $tags : new ReadTags(array_values($tags), []);

        return $this->modelCache()->lookup($key, $read->tags, $read->reads);
    }

    private function modelCache(): Cache
    {
        return app('model-cache.cache');
    }
}
