<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\Locking;

use Pollora\Media\Domain\Contracts\GenerationLock;
use Pollora\Media\Domain\Contracts\LockHandle;

/**
 * Locks with `flock()` on files of a folder: per server, which is what the
 * ceiling on simultaneous generations asks for, and it needs neither WordPress
 * nor Laravel nor a cache. The system releases a lock when its process ends,
 * even a crashed one.
 */
final readonly class FlockGenerationLock implements GenerationLock
{
    private const int POLL_MICROSECONDS = 50_000;

    public function __construct(private string $directory) {}

    public function slot(int $max, int $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + $waitSeconds;

        do {
            for ($index = 0; $index < $max; $index++) {
                $handle = $this->try('slot-'.$index);

                if ($handle instanceof LockHandle) {
                    return $handle;
                }
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    public function forFile(string $key, int $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + $waitSeconds;

        do {
            $handle = $this->try('file-'.sha1($key));

            if ($handle instanceof LockHandle) {
                return $handle;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    private function try(string $name): ?LockHandle
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }

        $stream = @fopen($this->directory.'/'.$name.'.lock', 'c');

        if ($stream === false) {
            return null;
        }

        if (! flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            return null;
        }

        return new FlockHandle($stream);
    }
}
