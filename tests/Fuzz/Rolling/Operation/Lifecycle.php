<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum Lifecycle: string
{
    /** A pod of the release starts, as a new pod of a deploy or a rollback does. */
    case Start = 'start';

    /** A pod stops, and whatever transaction it held rolls back with its connection. */
    case Stop = 'stop';

    /** A pod restarts with its release, losing what it kept in memory. */
    case Restart = 'restart';
}
