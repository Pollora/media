<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * What says where a size lives: the URL of a size of an original. The declaration of the sizes
 * does not change from one driver to another; only the shape of the URL does, and it is the
 * same before and after the file exists.
 */
interface ThumbnailDriver
{
    /**
     * @param  string  $originalKey  Path of the original, relative to the library
     */
    public function url(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string;

    /**
     * Whether the sizes are files of the library, made by the module, rather than a URL a service resolves.
     */
    public function makesFiles(): bool;
}
