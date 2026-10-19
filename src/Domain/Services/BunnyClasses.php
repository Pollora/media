<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * What a declared size is as an image class of Bunny Optimizer: the parameters of the Dynamic Images API.
 *
 * Bunny has no "cover" mode: width and height together fit the image in the box. A cover is a crop to the
 * ratio (`aspect_ratio`) and then a width. The documentation does not describe the combination; it was checked
 * on a real pull zone (2026-10-19), for an original that is big enough and for one that is too small, with and
 * without `upscaling`: the file has the dimensions `SizeGeometry` works out.
 *
 * @see https://bunny.net/docs/optimizer/dynamic-images/overview
 */
final class BunnyClasses
{
    /**
     * The name of the class of a size. Bunny accepts only letters and digits in a class name (`hero-640w` and
     * `my_size` are refused with "Invalid class name"), so what is not one is dropped: `hero640w`, `mysize`.
     */
    public function className(string $sizeName): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($sizeName));
    }

    /**
     * Why a size cannot have a class of its own, or null when it can: its class name is empty, or an
     * earlier size already has it (the first declared keeps it).
     *
     * @param  list<string>  $sizeNames  Every size to serve, in declaration order
     */
    public function refusal(string $sizeName, array $sizeNames): ?string
    {
        $class = $this->className($sizeName);

        if ($class === '') {
            return sprintf('The size "%s" has no letter or digit: Bunny class names are made of letters and digits only.', $sizeName);
        }

        foreach ($sizeNames as $other) {
            if ($other === $sizeName) {
                return null;
            }

            if ($this->className($other) === $class) {
                return sprintf('The size "%s" would get the Bunny class "%s", which "%s" already has (a class name keeps only letters and digits). Rename one of them.', $sizeName, $class, $other);
            }
        }

        return null;
    }

    /**
     * @return array<string, string> Sorted by name, so two exports of the same size compare equal
     */
    public function properties(ImageSizeSpec $spec): array
    {
        $properties = [];

        if ($spec->fit === Fit::Cover) {
            $divisor = $this->gcd((int) $spec->width, (int) $spec->height);
            $properties['aspect_ratio'] = ((int) $spec->width / $divisor).':'.((int) $spec->height / $divisor);
            $properties['width'] = (string) $spec->width;
        } else {
            $spec->width !== null && $properties['width'] = (string) $spec->width;
            $spec->height !== null && $properties['height'] = (string) $spec->height;
        }

        $properties['quality'] = (string) $spec->quality;

        match ($spec->format) {
            OutputFormat::Webp => $properties['format'] = 'webp',
            OutputFormat::Avif => $properties['format'] = 'avif',
            OutputFormat::Original => null,
        };

        $spec->upscale && $properties['upscaling'] = 'resampling';

        ksort($properties);

        return $properties;
    }

    /**
     * What Bunny does not guarantee for this size.
     *
     * @return list<string>
     */
    public function caveats(ImageSizeSpec $spec): array
    {
        $caveats = [];

        if ($spec->format === OutputFormat::Avif) {
            $caveats[] = sprintf('"%s" is AVIF: Bunny encodes AVIF up to 4 megapixels and falls back to another format above.', $spec->name);
        }

        return $caveats;
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? max(1, $a) : $this->gcd($b, $a % $b);
    }
}
