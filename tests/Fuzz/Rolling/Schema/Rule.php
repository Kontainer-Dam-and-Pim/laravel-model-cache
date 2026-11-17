<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Fuzz\Rolling\Schema;

/**
 * A foreign key's referential action, as a migration declares it.
 */
enum Rule: string
{
    case Cascade = 'cascade';
    case SetNull = 'set null';
    case SetDefault = 'set default';
    case Restrict = 'restrict';
    case NoAction = 'no action';

    /**
     * @return list<self>
     */
    public static function supportedBy(Driver $driver): array
    {
        return array_values(array_filter(self::cases(), static fn (self $rule): bool => $rule !== self::SetDefault || $driver->supportsSetDefault()));
    }

    public function needsNullableColumns(): bool
    {
        return $this === self::SetNull;
    }
}
