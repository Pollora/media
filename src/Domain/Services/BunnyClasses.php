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
 * ratio (`aspect_ratio`) and then a width; that combination follows the documented order of operations
 * (crops, then resizing) but is not documented as such, hence the caveat.
 *
 * @see https://bunny.net/docs/optimizer/dynamic-images/overview
 */
final class BunnyClasses
{
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

        if ($spec->fit === Fit::Cover) {
            $caveats[] = sprintf('"%s" is a cover: Bunny has no such mode, it is made with aspect_ratio and a width. Check the result on your pull zone.', $spec->name);
        }

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
