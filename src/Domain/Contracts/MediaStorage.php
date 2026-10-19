<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

/**
 * Where the media files live: what the package needs from a disk, and no more.
 * Paths are relative to the media library (`2026/10/photo.jpg`).
 */
interface MediaStorage
{
    public function exists(string $path): bool;

    /**
     * @return resource
     */
    public function readStream(string $path);

    /**
     * @param  string|resource  $contents
     * @param  array<string, mixed>  $options  Passed to the driver: `CacheControl` for S3-like disks
     */
    public function write(string $path, mixed $contents, array $options = []): void;

    public function delete(string $path): void;

    public function deleteDirectory(string $path): void;

    public function copy(string $source, string $destination): void;

    public function move(string $source, string $destination): void;

    /**
     * @return 'public'|'private'
     */
    public function visibility(string $path): string;

    /**
     * @param  'public'|'private'  $visibility
     */
    public function setVisibility(string $path, string $visibility): void;

    /**
     * A URL that gives access to a private file until it expires, or null when the disk cannot sign one.
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt): ?string;

    public function size(string $path): int;

    public function lastModified(string $path): int;

    /**
     * What a directory holds, one level deep.
     *
     * @return list<array{path: string, type: 'file'|'dir', size: int, lastModified: int}>
     */
    public function list(string $path, bool $deep = false): array;
}
