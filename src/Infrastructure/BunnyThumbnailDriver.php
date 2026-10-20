<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\BunnyClasses;

/**
 * Bunny Optimizer: the URL of a size is the URL of the original, the name of an image class and the version of
 * the size (`photo.jpg?class=card&v=3f9a1c2b`). Bunny ignores `v`, but its cache does not: without it, a class
 * that was changed keeps being served as it was under the same URL. The class is defined at Bunny
 * (`thumbs export`), not here: no file is made.
 *
 * Bunny accepts only letters and digits in the name of a class, so the class of `hero-640w` is `hero640w`;
 * two sizes whose classes would be the same cannot both be served, and the second is refused.
 *
 * @see https://bunny.net/docs/optimizer/image-classes
 */
final readonly class BunnyThumbnailDriver implements ThumbnailDriver
{
    private BunnyClasses $classes;

    public function __construct(private MediaConfig $config, private ImageSizes $sizes)
    {
        $this->classes = new BunnyClasses;
    }

    public function url(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string
    {
        return $this->config->url.'/'.ltrim($originalKey, '/').'?class='.$this->classes->className($spec->name).'&v='.$spec->version();
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

        if (! is_string($class)) {
            return null;
        }

        foreach ($this->sizes->withVariants() as $name => $spec) {
            if ($this->classes->className($name) === $class && $this->refusal($spec) === null) {
                return $name;
            }
        }

        return null;
    }

    public function refusal(ImageSizeSpec $spec): ?string
    {
        return $this->classes->refusal($spec->name, array_keys($this->sizes->withVariants()), $spec);
    }
}
