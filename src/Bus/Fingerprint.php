<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Bus;

/**
 * A stable SHA-256 of a value: object keys sorted at every level, so the same
 * params in another order give the same hash.
 */
final class Fingerprint
{
    public static function of(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
