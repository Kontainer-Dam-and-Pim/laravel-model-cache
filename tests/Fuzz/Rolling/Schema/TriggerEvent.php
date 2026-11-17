<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

enum TriggerEvent: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * The pseudo-record holding the changed row in a row-level trigger body.
     */
    public function rowReference(): string
    {
        return $this === self::Delete ? 'old' : 'new';
    }
}
