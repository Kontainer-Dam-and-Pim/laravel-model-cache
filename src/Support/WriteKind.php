<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

/**
 * The kinds of `Write`, which decide the dependents fields it looks up.
 */
enum WriteKind
{
    case Insert;
    case ColumnUpdate;

    /** Inserts rows, updating the columns of those whose key exists. */
    case Upsert;

    /** Deletes the rows whose key exists and inserts the new ones, as `REPLACE` does. */
    case Replace;
    case Delete;
    case Truncate;
    case Merge;
    case Unknown;
}
