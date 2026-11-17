<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

enum ViewKind: string
{
    /** The primary key and label of every row of a table. */
    case Projection = 'projection';

    /** Each child row's key with the label of the parent its foreign key references. */
    case ParentJoin = 'join';

    /** How many child rows reference each value of a foreign key. */
    case ChildCount = 'count';

    /** Every row of another view. */
    case OverView = 'over';
}
