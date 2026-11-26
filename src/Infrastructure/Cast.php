<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

use Stringable;

/**
 * Reads what WordPress, WP-CLI and constants hand over without a type. A value that is neither a scalar nor
 * stringable (an array, a plain object) has no text and no number: it reads as `''` or `0`.
 *
 * @internal
 */
final class Cast
{
    public static function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }

    public static function integer(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : (int) self::text($value);
    }

    /**
     * Like text(), for a value that may be absent.
     */
    public static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }
}
