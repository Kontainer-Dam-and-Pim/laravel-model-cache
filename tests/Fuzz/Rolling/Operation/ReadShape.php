<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * What a read selects beyond rows matching its conditions. Shapes naming a foreign key read the
 * table on its child or parent side through it, as the shape says; shapes naming a relation use
 * the release's model.
 */
enum ReadShape: string
{
    case Rows = 'rows';
    case Count = 'count';
    case Sum = 'sum';
    case Max = 'max';

    /** The rows with the label of the parent their foreign key references, by a join. */
    case JoinParent = 'joinParent';

    /** The rows with a relation of the model eager loaded. */
    case EagerLoad = 'eagerLoad';

    /** The rows with how many of a relation's rows each has, by `withCount()`. */
    case WithCount = 'withCount';

    /** The rows a relation has rows with the label for, by `whereHas()`. */
    case WhereHas = 'whereHas';

    /** The rows of a soft deleting model, deleted ones included. */
    case WithTrashed = 'withTrashed';

    /** The rows of a soft deleting model that are deleted. */
    case OnlyTrashed = 'onlyTrashed';

    /** Child rows whose parent has the label, by `whereIn()` with a subquery. */
    case ChildrenOfLabelledParents = 'childrenOfLabelledParents';

    /** Parent rows with a child, by `whereExists()`. */
    case ParentsWithChildren = 'parentsWithChildren';

    /** Parent rows with how many children each has, by `selectSub()`. */
    case ChildCounts = 'childCounts';

    /** Parent rows joined to their counts of children, by `joinSub()`. */
    case JoinedChildCounts = 'joinedChildCounts';

    /** How many children each value of the foreign key has, by `fromSub()`. */
    case GroupedChildCounts = 'groupedChildCounts';

    /** The labels of the table and of another, by `union()`. */
    case Union = 'union';

    /** The three parent rows with the most children, by `orderBy()` with a subquery. */
    case MostChildren = 'mostChildren';

    /** Parent rows with a child, by `whereRaw()` with an `EXISTS` in SQL. */
    case RawParentsWithChildren = 'rawParentsWithChildren';

    /** Parent rows with how many children each has, by `selectRaw()` with a subquery in SQL. */
    case RawChildCounts = 'rawChildCounts';

    /**
     * Whether the shape names a foreign key whose parent is the read table, rather than its
     * child.
     */
    public function readsParentSide(): bool
    {
        return in_array($this, [self::ParentsWithChildren, self::ChildCounts, self::JoinedChildCounts, self::MostChildren, self::RawParentsWithChildren, self::RawChildCounts], true);
    }

    public function namesForeignKey(): bool
    {
        return $this->readsParentSide() || in_array($this, [self::JoinParent, self::ChildrenOfLabelledParents, self::GroupedChildCounts], true);
    }
}
