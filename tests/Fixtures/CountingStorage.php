<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use Pollora\Media\Domain\Contracts\MediaStorage;

/**
 * A real storage that counts what the wrapper asks of it. It decorates, it does not replace.
 */
final class CountingStorage implements MediaStorage
{
    public int $existsCalls = 0;

    public int $readCalls = 0;

    public int $statCalls = 0;

    /** @var array<string, mixed> */
    public array $lastWriteOptions = [];

    /** @var array<string, array<string, mixed>> The options of each write, by path */
    public array $writeOptions = [];

    public function __construct(private readonly MediaStorage $inner) {}

    public function exists(string $path): bool
    {
        $this->existsCalls++;

        return $this->inner->exists($path);
    }

    public function readStream(string $path)
    {
        $this->readCalls++;

        return $this->inner->readStream($path);
    }

    public function write(string $path, mixed $contents, array $options = []): void
    {
        $this->lastWriteOptions = $options;
        $this->writeOptions[$path] = $options;
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

    public function visibility(string $path): string
    {
        return $this->inner->visibility($path);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt): ?string
    {
        return $this->inner->temporaryUrl($path, $expiresAt);
    }

    public function size(string $path): int
    {
        $this->statCalls++;

        return $this->inner->size($path);
    }

    public function lastModified(string $path): int
    {
        $this->statCalls++;

        return $this->inner->lastModified($path);
    }

    public function list(string $path, bool $deep = false): array
    {
        return $this->inner->list($path, $deep);
    }
}
