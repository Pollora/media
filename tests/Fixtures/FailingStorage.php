<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use League\Flysystem\UnableToWriteFile;
use Pollora\Media\Domain\Contracts\MediaStorage;

/**
 * A real storage that refuses to write one path, as a full or read-only disk would.
 */
final readonly class FailingStorage implements MediaStorage
{
    public function __construct(private MediaStorage $inner, private string $failing) {}

    public function exists(string $path): bool
    {
        return $this->inner->exists($path);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function write(string $path, mixed $contents, array $options = []): void
    {
        if ($path === $this->failing) {
            throw UnableToWriteFile::atLocation($path, 'refused');
        }

        $this->inner->write($path, $contents, $options);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function copy(string $source, string $destination): void
    {
        $this->inner->copy($source, $destination);
    }

    public function move(string $source, string $destination): void
    {
        $this->inner->move($source, $destination);
    }

    public function size(string $path): int
    {
        return $this->inner->size($path);
    }

    public function lastModified(string $path): int
    {
        return $this->inner->lastModified($path);
    }

    public function list(string $path, bool $deep = false): array
    {
        return $this->inner->list($path, $deep);
    }
}
