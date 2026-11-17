<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Concerns;

use Kontainer\ModelCache\Support\TableRead;

/**
 * What a read looks up: tags it depends on besides its tables, and its tables, which the lookup
 * script tags.
 */
final readonly class ReadTags
{
    /**
     * @param  list<string>  $tags
     * @param  list<TableRead>  $reads
     */
    public function __construct(
        public array $tags,
        public array $reads,
    ) {}
}
