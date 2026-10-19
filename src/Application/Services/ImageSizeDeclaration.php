<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use InvalidArgumentException;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * An image size being declared, call after call. It becomes an
 * ImageSizeSpec, checked, when the sizes are first read.
 */
final class ImageSizeDeclaration
{
    private ?Fit $fit = null;

    private ?int $width = null;

    private ?int $height = null;

    private OutputFormat $format = OutputFormat::Webp;

    private int $quality = 80;

    private bool $upscale = false;

    private bool $eager = false;

    /** @var list<int> */
    private array $widths = [];

    public function __construct(private readonly string $name) {}

    /**
     * Crops to exactly the box, centered.
     */
    public function cover(int $width, ?int $height = null): static
    {
        return $this->fit(Fit::Cover, $width, $height);
    }

    /**
     * Resizes to a width and/or a height, keeping the ratio. It never enlarges.
     */
    public function scale(?int $width = null, ?int $height = null): static
    {
        return $this->fit(Fit::Scale, $width, $height);
    }

    /**
     * Fits inside the box, keeping the ratio, without cropping.
     */
    public function contain(int $width, ?int $height = null): static
    {
        return $this->fit(Fit::Contain, $width, $height);
    }

    public function webp(int $quality = 80): static
    {
        return $this->format(OutputFormat::Webp, $quality);
    }

    public function avif(int $quality = 80): static
    {
        return $this->format(OutputFormat::Avif, $quality);
    }

    /**
     * Keeps the format of the original.
     */
    public function original(int $quality = 80): static
    {
        return $this->format(OutputFormat::Original, $quality);
    }

    /**
     * Extra widths for a `srcset`, each a bounded size of its own.
     *
     * @param  list<int>  $widths
     */
    public function widths(array $widths): static
    {
        $this->widths = $widths;

        return $this;
    }

    /**
     * Made when the image is uploaded.
     */
    public function eager(bool $eager = true): static
    {
        $this->eager = $eager;

        return $this;
    }

    /**
     * Allows enlarging an original smaller than the size.
     */
    public function upscale(bool $upscale = true): static
    {
        $this->upscale = $upscale;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws InvalidArgumentException When what was declared does not make a size
     */
    public function toSpec(): ImageSizeSpec
    {
        if (preg_match('/^[a-z0-9_]+$/', $this->name) !== 1) {
            throw new InvalidArgumentException(sprintf('The size name "%s" must be lowercase letters, digits and underscores.', $this->name));
        }

        if (! $this->fit instanceof Fit) {
            throw new InvalidArgumentException(sprintf('The size "%s" has no fit: call cover(), scale() or contain().', $this->name));
        }

        return new ImageSizeSpec($this->name, $this->fit, $this->width, $this->height, $this->format, $this->quality, $this->upscale, $this->eager, $this->widths);
    }

    private function fit(Fit $fit, ?int $width, ?int $height): static
    {
        $this->fit = $fit;
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    private function format(OutputFormat $format, int $quality): static
    {
        $this->format = $format;
        $this->quality = $quality;

        return $this;
    }
}
