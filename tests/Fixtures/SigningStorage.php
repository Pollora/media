<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use DateTimeInterface;
use League\Flysystem\UnableToSetVisibility;
use Pollora\Media\Domain\Contracts\MediaStorage;

/**
 * A real storage that signs URLs the way an object store does: the path and an expiry in a query string.
 * It records the visibility it was given, which a local disk would turn into file permissions.
 */
final class SigningStorage implements MediaStorage
{
    /** @var array<string, string> */
    public array $visibilities = [];

    public int $signed = 0;

    public function __construct(private readonly MediaStorage $inner) {}

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
        return $this->visibilities[$path] ?? 'public';
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (! $this->inner->exists($path)) {
            throw UnableToSetVisibility::atLocation($path, 'No such file.');
        }

        $this->visibilities[$path] = $visibility;
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt): ?string
    {
        $this->signed++;

        return 'https://bucket.s3.test/'.$path.'?X-Expires='.$expiresAt->getTimestamp().'&X-Signature=sig';
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
