<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum WriteKind: string
{
    case Insert = 'insert';
    case InsertGetId = 'insertGetId';
    case InsertOrIgnore = 'insertOrIgnore';
    case Upsert = 'upsert';
    case Update = 'update';
    case Increment = 'increment';
    case Delete = 'delete';
    case Truncate = 'truncate';

    /** A model found by the conditions, changed and saved, firing model events. */
    case Save = 'save';

    /** A model found by the conditions and deleted. */
    case DeleteModel = 'deleteModel';
}
