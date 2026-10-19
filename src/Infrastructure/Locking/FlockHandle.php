<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\Locking;

use Pollora\Media\Domain\Contracts\LockHandle;

/**
 * A lock on a file, released when the handle is, or when the process ends.
 */
final class FlockHandle implements LockHandle
{
    /** @param resource $stream */
    public function __construct(private $stream) {}

    public function release(): void
    {
        if (is_resource($this->stream)) {
            flock($this->stream, LOCK_UN);
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
