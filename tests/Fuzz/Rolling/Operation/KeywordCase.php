<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Operation;

enum KeywordCase: string
{
    case Lower = 'lower';
    case Upper = 'upper';
    case Capitalized = 'capitalized';

    public function apply(string $keyword): string
    {
        return match ($this) {
            self::Lower => strtolower($keyword),
            self::Upper => strtoupper($keyword),
            self::Capitalized => ucfirst(strtolower($keyword)),
        };
    }
}
