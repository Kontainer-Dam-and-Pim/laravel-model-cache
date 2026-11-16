<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

final readonly class CacheHit
{
    /**
     * @param  array<mixed>  $rows
     */
    public function __construct(
        public array $rows,
    ) {}
}
