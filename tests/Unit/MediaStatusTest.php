<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\MediaStatus;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

it('writes a probe file, reads it back and removes it', function (): void {
    $disk = new TemporaryStorage;

    $status = (new MediaStatus($disk->storage, new MediaConfig('https://media.test')))->check();

    expect($status)->toBe(['url' => 'https://media.test', 'writable' => true, 'error' => null])
        ->and(glob($disk->root.'/.pollora-media-status-*'))->toBe([]);
});

it('says why a disk does not take a file', function (): void {
    $disk = new TemporaryStorage;
    $storage = new class($disk->storage) implements MediaStorage
    {
        public function __construct(private MediaStorage $inner) {}

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
            throw new RuntimeException('Access Denied');
        }

        public function delete(string $path): void {}

        public function deleteDirectory(string $path): void {}

        public function copy(string $source, string $destination): void {}

        public function move(string $source, string $destination): void {}

        public function visibility(string $path): string
        {
            return 'public';
        }

        public function setVisibility(string $path, string $visibility): void {}

        public function temporaryUrl(string $path, DateTimeInterface $expiresAt): ?string
        {
            return null;
        }

        public function size(string $path): int
        {
            return 0;
        }

        public function lastModified(string $path): int
        {
            return 0;
        }

        public function list(string $path, bool $deep = false): array
        {
            return [];
        }
    };

    $status = (new MediaStatus($storage, new MediaConfig('https://media.test')))->check();

    expect($status['writable'])->toBeFalse()->and($status['error'])->toBe('Access Denied');
});
