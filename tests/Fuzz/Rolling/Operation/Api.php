<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

/**
 * How application code reaches the database: the query builder, the release's models, or the
 * query builder with conditions written as raw SQL.
 */
enum Api: string
{
    case Builder = 'builder';
    case Model = 'model';
    case Raw = 'raw';
}
