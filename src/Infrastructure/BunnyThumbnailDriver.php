<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;

/**
 * Bunny Optimizer: the URL of a size is the URL of the original and the name of an image class
 * (`photo.jpg?class=card`). The class is defined at Bunny (`thumbs export`), not here: no file is made.
 *
 * @see https://bunny.net/docs/optimizer/image-classes
 */
final readonly class BunnyThumbnailDriver implements ThumbnailDriver
{
    public function __construct(private MediaConfig $config) {}

    public function url(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string
    {
        return $this->config->url.'/'.ltrim($originalKey, '/').'?class='.$spec->name;
    }

    public function makesFiles(): bool
    {
        return false;
    }

    public function sizeOf(string $url): ?string
    {
        if (! str_starts_with($url, $this->config->url.'/')) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $class = $query['class'] ?? null;

        return is_string($class) && preg_match(SizeName::PATTERN, $class) === 1 ? $class : null;
    }

    public function refusal(ImageSizeSpec $spec): ?string
    {
        return null;
    }
}
