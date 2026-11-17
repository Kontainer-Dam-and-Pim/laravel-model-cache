<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

use InvalidArgumentException;

final readonly class CacheMiss
{
    private const string FINGERPRINT_PATTERN = '/^[0-9a-f]{40}$/';

    /**
     * @param  list<string>  $tags  the tags the entry depends on besides its cascade tags, as the lookup resolved them,
     *                              checked when a result read from a lagging replica is stored (see `Cache::store()`)
     */
    public function __construct(
        public Generation $generation,
        public string $key,
        public string $fingerprint,
        public string $table = '',
        public array $tags = [],
    ) {
        if (preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Model Cache fingerprint "%s", expected 40 lowercase hex characters', $fingerprint));
        }
    }
}
