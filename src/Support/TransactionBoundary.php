<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

/**
 * What a raw statement does to the connection's transaction.
 */
enum TransactionBoundary
{
    case Begins;
    case Ends;
}
