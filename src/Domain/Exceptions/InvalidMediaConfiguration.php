<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when the media constants describe a disk that cannot be built.
 */
final class InvalidMediaConfiguration extends RuntimeException
{
    /**
     * @param  mixed  $driver  What POLLORA_MEDIA_DRIVER holds
     */
    public static function unknownDriver(mixed $driver): self
    {
        return new self(sprintf(
            'POLLORA_MEDIA_DRIVER "%s" is not known; use "local" or "s3", or provide a filesystem with the pollora/media/filesystem filter.',
            is_scalar($driver) ? (string) $driver : get_debug_type($driver),
        ));
    }

    public static function missingBucket(): self
    {
        return new self('POLLORA_MEDIA_BUCKET is required with POLLORA_MEDIA_DRIVER "s3". Define it in wp-config.php or the environment.');
    }

    public static function missingS3Package(): self
    {
        return new self('The S3 driver needs league/flysystem-aws-s3-v3: run "composer require league/flysystem-aws-s3-v3".');
    }
}
