<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Throwable;

/**
 * Checks that the media storage works the way an upload needs it to: the
 * public URL is set and the disk takes a file, gives it back, and deletes it.
 */
final readonly class MediaStatus
{
    public function __construct(
        private MediaStorage $storage,
        private MediaConfig $config,
    ) {}

    /**
     * @return array{url: string, writable: bool, error: string|null}
     */
    public function check(): array
    {
        $probe = '.pollora-media-status-'.bin2hex(random_bytes(4));

        try {
            $this->storage->write($probe, 'ok');
            $readable = stream_get_contents($this->storage->readStream($probe)) === 'ok';
            $this->storage->delete($probe);

            return ['url' => $this->config->url, 'writable' => $readable, 'error' => $readable ? null : 'The file written could not be read back.'];
        } catch (Throwable $throwable) {
            return ['url' => $this->config->url, 'writable' => false, 'error' => $throwable->getMessage()];
        }
    }
}
