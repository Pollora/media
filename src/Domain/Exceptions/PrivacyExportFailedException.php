<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a personal data export cannot be put on the media disk.
 */
final class PrivacyExportFailedException extends RuntimeException
{
    /**
     * @param  string  $archive  The path of the archive WordPress built
     */
    public static function unreadableArchive(string $archive): self
    {
        return new self(sprintf('The personal data export %s cannot be read: it was not copied to the media disk.', basename($archive)));
    }
}
