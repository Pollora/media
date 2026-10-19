<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * Makes a size of an image. The engine is not the package's concern: WordPress's own
 * image editor (GD or Imagick) by default, another in a host that has one.
 */
interface ImageProcessor
{
    /**
     * Reads the original, makes the size, writes it to the destination.
     *
     * @param  string  $sourceKey  Path of the original, relative to the library
     * @param  string  $destinationKey  Where to write the size, relative to the library
     * @return array{width: int, height: int, mime: string, filesize: int}
     *
     * @throws \RuntimeException When the image cannot be made, or the format is not supported
     */
    public function make(string $sourceKey, string $destinationKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array;
}
