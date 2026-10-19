<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Pollora\Media\Domain\Models\MediaConfig;

/**
 * The URL of a media: the public URL and a relative path, put side by side.
 * Built, never asked to the storage: no call to the disk, whatever the driver.
 */
final readonly class MediaUrl
{
    public function __construct(private MediaConfig $config) {}

    /**
     * @param  string  $path  Relative to the media library (`2026/10/photo.jpg`)
     */
    public function for(string $path): string
    {
        return $this->config->url.'/'.ltrim($path, '/');
    }
}
