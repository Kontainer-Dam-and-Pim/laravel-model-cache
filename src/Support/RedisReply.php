<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Support;

use UnexpectedValueException;

final class RedisReply
{
    /**
     * @return list<mixed>
     */
    public static function list(mixed $value, int $minimumLength, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimumLength) {
            throw new UnexpectedValueException(sprintf('Malformed Model Cache %s: expected a list of at least %d items, got %s', $context, $minimumLength, self::describe($value)));
        }

        return $value;
    }

    public static function string(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed Model Cache %s: expected a string, got %s', $context, self::describe($value)));
        }

        return $value;
    }

    public static function integer(mixed $value, string $context): int
    {
        $parsed = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : $value;

        if (! is_int($parsed)) {
            throw new UnexpectedValueException(sprintf('Malformed Model Cache %s: expected an integer, got %s', $context, self::describe($value)));
        }

        return $parsed;
    }

    private static function describe(mixed $value): string
    {
        return is_array($value) ? 'array of '.count($value) : get_debug_type($value);
    }
}
