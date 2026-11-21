<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Models;

use InvalidArgumentException;
use Pollora\Media\Domain\Enums\Anchor;
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
        public Anchor $anchor = Anchor::Center,
        public ?FocalPoint $focal = null,
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

        if ($anchor !== Anchor::Center && $fit !== Fit::Cover) {
            throw new InvalidArgumentException(sprintf('The size "%s" is a %s: an anchor only means something for a cover.', $name, $fit->value));
        }

        if ($focal instanceof FocalPoint && $fit !== Fit::Cover) {
            throw new InvalidArgumentException(sprintf('The size "%s" is a %s: a focal point only means something for a cover.', $name, $fit->value));
        }
    }

    /**
     * What identifies the size, for the signature of its files: a change to any of it is a new file name.
     */
    public function fingerprint(): string
    {
        $parts = [$this->fit->value, $this->width ?? '', $this->height ?? '', $this->format->value, $this->quality, (int) $this->upscale];

        // Left out when centered, so the files and the URLs of the sizes declared before anchors existed do not change.
        if ($this->anchor !== Anchor::Center) {
            $parts[] = $this->anchor->value;
        }

        // Left out when there is none, for the same reason: a size of an image without a focal point keeps its name.
        if ($this->focal instanceof FocalPoint) {
            $parts[] = 'focal:'.$this->focal->fingerprint();
        }

        return implode('|', $parts);
    }

    /**
     * The size as it applies to one image: a cover crops around the focal point of the image instead of by the
     * anchor, and its files take the point in their name and signature, so moving the point never leaves a stale
     * copy. A size that does not crop (scale, contain) is returned as it is: a focal point never changes its
     * dimensions nor its name.
     */
    public function focusedOn(?FocalPoint $focal): self
    {
        if ($this->fit !== Fit::Cover || ! $focal instanceof FocalPoint) {
            return $this;
        }

        return new self($this->name, $this->fit, $this->width, $this->height, $this->format, $this->quality, $this->upscale, $this->eager, $this->widths, $this->anchor, $focal);
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
