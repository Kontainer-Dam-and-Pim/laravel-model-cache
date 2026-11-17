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

    /** Updates the label of rows through an updatable view of their table; `table` names the view. */
    case UpdateView = 'updateView';

    /** Deletes rows through an updatable view of their table; `table` names the view. */
    case DeleteView = 'deleteView';

    /** Restores the soft deleted rows the conditions select. */
    case Restore = 'restore';

    /** Deletes the rows the conditions select, soft deleted ones included, for good. */
    case ForceDelete = 'forceDelete';

    /** Copies the label of the selected rows into the audit table, by `insertUsing()`. */
    case InsertUsing = 'insertUsing';

    /** Updates the row with the key, or inserts it, by `updateOrInsert()`. */
    case UpdateOrInsert = 'updateOrInsert';

    /** Updates rows whose parent has the label, joined to it. */
    case UpdateJoined = 'updateJoined';

    /** Deletes rows whose parent has the label, by `whereExists()`. */
    case DeleteWhereParentExists = 'deleteWhereParentExists';

    case IncrementEach = 'incrementEach';

    /** Two updates in one `DB::unprepared()` call. */
    case Statements = 'statements';

    /** `REPLACE INTO`, deleting the rows whose key exists before inserting. */
    case Replace = 'replace';

    /** A delete of the rows a common table expression selects. */
    case CteDelete = 'cteDelete';

    /** `MERGE`, updating the row with the key or inserting it. */
    case Merge = 'merge';
}
