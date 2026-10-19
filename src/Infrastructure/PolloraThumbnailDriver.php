<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\SizedFileName;

/**
 * The default driver: a size is a file of the library, named `photo-card-600x400-{signature}.webp`,
 * made when the image is uploaded or the first time it is asked for (the fallback route).
 */
final readonly class PolloraThumbnailDriver implements ThumbnailDriver
{
    public function __construct(
        private SizedFileName $names,
        private MediaConfig $config,
    ) {}

    public function url(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string
    {
        return $this->config->url.'/'.ltrim($this->names->for($originalKey, $spec, $originalWidth, $originalHeight), '/');
    }

    public function makesFiles(): bool
    {
        return true;
    }
}
