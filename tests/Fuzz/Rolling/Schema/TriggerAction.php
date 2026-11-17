<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

enum TriggerAction: string
{
    /** Inserts a row into the audit table. */
    case InsertAudit = 'insert';

    /** Sets the label of the target rows. */
    case UpdateTarget = 'update';

    /** Deletes the target rows. */
    case DeleteTarget = 'delete';
}
