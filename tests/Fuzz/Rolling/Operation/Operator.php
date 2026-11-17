<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum Operator: string
{
    case Equals = '=';
    case In = 'in';
    case GreaterThan = '>';
    case IsNull = 'null';
}
