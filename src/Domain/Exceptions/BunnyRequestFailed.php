<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when the Bunny API cannot be reached or refuses a request.
 */
final class BunnyRequestFailed extends RuntimeException
{
    /**
     * @param  string  $reason  What the HTTP client reported
     */
    public static function unreachable(string $reason): self
    {
        return new self('Bunny could not be reached: '.$reason);
    }

    public static function refused(int $status, string $method, string|int $pullZone): self
    {
        return new self(sprintf('Bunny answered %d to %s on the pull zone %s. Check the API key and the pull zone identifier.', $status, $method, $pullZone));
    }
}
