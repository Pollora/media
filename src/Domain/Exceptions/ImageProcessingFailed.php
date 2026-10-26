<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a thumbnail cannot be made.
 */
final class ImageProcessingFailed extends RuntimeException
{
    /**
     * @param  string  $mime  The format asked for, or an empty string when it is unknown
     * @param  string  $size  The name of the size that is not made
     */
    public static function unsupportedFormat(string $mime, string $size): self
    {
        return new self(sprintf(
            'The image editor of this server cannot write %s: size "%s" is not made. Install an image library that supports it (Imagick with the format, or GD), or choose another output format.',
            $mime === '' ? 'this format' : $mime,
            $size,
        ));
    }

    /**
     * @param  string  $reason  What the WordPress image editor reported
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
