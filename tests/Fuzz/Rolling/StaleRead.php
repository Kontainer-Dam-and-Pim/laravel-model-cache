<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling;

use RuntimeException;

/**
 * A pod read a cached outcome that differs from the database's, with the read it made, by which
 * shrinking keeps to the same failure instead of wandering off to another.
 */
final class StaleRead extends RuntimeException
{
    public function __construct(string $message, public readonly string $read)
    {
        parent::__construct($message);
    }
}
