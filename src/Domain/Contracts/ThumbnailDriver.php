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

    /**
     * The name of the declared size a URL of this driver stands for, or null when the URL is not one it made.
     */
    public function sizeOf(string $url): ?string;

    /**
     * Why the driver cannot serve a size, or null when it can. A size it refuses is left to WordPress.
     */
    public function refusal(ImageSizeSpec $spec): ?string;
}
