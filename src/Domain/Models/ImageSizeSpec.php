<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Models;

use InvalidArgumentException;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;

/**
 * A declared image size: a name, how to fit the image in a box, the format
 * it is written in, and whether it is made at once or on demand.
 */
final readonly class ImageSizeSpec
{
    /**
     * @param  list<int>  $widths  Extra widths for a `srcset`, each a bounded size of its own
     *
     * @throws InvalidArgumentException When the box cannot say a size
     */
    public function __construct(
        public string $name,
        public Fit $fit,
        public ?int $width,
        public ?int $height,
        public OutputFormat $format = OutputFormat::Webp,
        public int $quality = 80,
        public bool $upscale = false,
        public bool $eager = false,
        public array $widths = [],
    ) {
        if ($width === null && $height === null) {
            throw new InvalidArgumentException(sprintf('The size "%s" needs a width, a height, or both.', $name));
        }

        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new InvalidArgumentException(sprintf('The size "%s" needs dimensions of at least 1 pixel.', $name));
        }

        if ($fit !== Fit::Scale && ($width === null || $height === null)) {
            throw new InvalidArgumentException(sprintf('The size "%s" is a %s: it needs a width and a height.', $name, $fit->value));
        }
    }

    /**
     * What identifies the size, for the signature of its files: a change to any of it is a new file name.
     */
    public function fingerprint(): string
    {
        return implode('|', [$this->fit->value, $this->width ?? '', $this->height ?? '', $this->format->value, $this->quality, (int) $this->upscale]);
    }

    /**
     * A short tag that changes when the size does, for the URL of a service that makes the size (the URL of
     * a file of the module changes by its name). Without it a CDN keeps serving the old size under the same
     * URL after a class or a preset was changed: checked on a real Bunny pull zone.
     */
    public function version(): string
    {
        return substr(sha1($this->fingerprint()), 0, 8);
    }
}
