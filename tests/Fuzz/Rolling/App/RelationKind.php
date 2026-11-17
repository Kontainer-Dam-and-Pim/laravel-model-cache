<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\App;

enum RelationKind: string
{
    case BelongsTo = 'belongsTo';
    case HasMany = 'hasMany';
}
