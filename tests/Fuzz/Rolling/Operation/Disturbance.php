<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum Disturbance: string
{
    /** `model-cache:flush`. */
    case Flush = 'flush';

    /** Redis loses everything, as a restart without persistence does. */
    case Empty = 'empty';

    /** Redis evicts some keys under memory pressure. */
    case Evict = 'evict';
}
