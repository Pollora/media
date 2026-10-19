<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\Flysystem;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Pollora\Media\Domain\Contracts\MediaStorage;

/**
 * The media storage on any Flysystem v3 filesystem. A Laravel disk is one
 * (`Storage::disk($name)->getDriver()`), and so is a filesystem built from
 * constants without Laravel.
 */
final readonly class FlysystemMediaStorage implements MediaStorage
{
    public function __construct(private FilesystemOperator $filesystem) {}

    public function exists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    public function readStream(string $path)
    {
        return $this->filesystem->readStream($path);
    }

    public function write(string $path, mixed $contents, array $options = []): void
    {
        is_resource($contents)
            ? $this->filesystem->writeStream($path, $contents, $options)
            : $this->filesystem->write($path, (string) $contents, $options);
    }

    public function delete(string $path): void
    {
        $this->filesystem->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->filesystem->deleteDirectory($path);
    }

    public function copy(string $source, string $destination): void
    {
        $this->filesystem->copy($source, $destination);
    }

    public function move(string $source, string $destination): void
    {
        $this->filesystem->move($source, $destination);
    }

    public function size(string $path): int
    {
        return $this->filesystem->fileSize($path);
    }

    public function lastModified(string $path): int
    {
        return $this->filesystem->lastModified($path);
    }

    public function list(string $path, bool $deep = false): array
    {
        $entries = [];

        /** @var StorageAttributes $attributes */
        foreach ($this->filesystem->listContents($path, $deep) as $attributes) {
            $entries[] = [
                'path' => $attributes->path(),
                'type' => $attributes->isDir() ? 'dir' : 'file',
                'size' => $attributes->isFile() ? (int) ($attributes->jsonSerialize()['file_size'] ?? 0) : 0,
                'lastModified' => (int) ($attributes->lastModified() ?? 0),
            ];
        }

        return $entries;
    }
}
