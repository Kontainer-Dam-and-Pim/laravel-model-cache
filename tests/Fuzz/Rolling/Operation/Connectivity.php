<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum Connectivity: string
{
    /** The pod cannot reach Redis, as in an outage or a network partition. */
    case Lost = 'lost';

    /** The pod reaches Redis again. */
    case Restored = 'restored';
}
