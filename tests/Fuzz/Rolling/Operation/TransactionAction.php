<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum TransactionAction: string
{
    case Begin = 'begin';
    case Commit = 'commit';
    case Rollback = 'rollback';
}
