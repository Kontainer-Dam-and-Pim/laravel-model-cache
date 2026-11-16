<?php

declare(strict_types=1);

namespace Kontainer\ModelCache;

use InvalidArgumentException;
use Kontainer\ModelCache\Support\ConfigValue;

enum CacheScope: string
{
    case All = 'all';
    case Models = 'models';

    public static function fromConfig(): self
    {
        $value = ConfigValue::string('model-cache.scope', self::All->value);

        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'Invalid model-cache.scope "%s", expected one of: %s',
            $value,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }
}
