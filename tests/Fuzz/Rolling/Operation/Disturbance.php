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

    /** Redis stops answering every pod, as in an outage. */
    case Outage = 'outage';

    /** Redis answers again after an outage. */
    case Recovery = 'recovery';
}
