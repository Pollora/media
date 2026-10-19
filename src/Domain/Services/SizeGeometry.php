<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * What a size makes of an image, worked out from the dimensions of the
 * original alone: a pure computation, so a URL can say its width and height
 * without reading a file.
 */
final class SizeGeometry
{
    /**
     * @return array{0: int, 1: int} Width and height of the file the size makes
     */
    public function dimensions(ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        return match ($spec->fit) {
            Fit::Cover => $this->cover($spec, $originalWidth, $originalHeight),
            Fit::Scale => $this->scale($spec, $originalWidth, $originalHeight),
            Fit::Contain => $this->contain($spec, $originalWidth, $originalHeight),
        };
    }

    /**
     * The part of the original a cover keeps: the largest centered region with the ratio of the box.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function coverRegion(int $originalWidth, int $originalHeight, int $boxWidth, int $boxHeight): array
    {
        $ratio = $boxWidth / $boxHeight;

        if ($originalWidth / $originalHeight > $ratio) {
            $height = $originalHeight;
            $width = (int) round($originalHeight * $ratio);
        } else {
            $width = $originalWidth;
            $height = (int) round($originalWidth / $ratio);
        }

        return ['x' => intdiv($originalWidth - $width, 2), 'y' => intdiv($originalHeight - $height, 2), 'width' => $width, 'height' => $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function cover(ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        $width = (int) $spec->width;
        $height = (int) $spec->height;

        if ($originalWidth >= $width && $originalHeight >= $height || $spec->upscale) {
            return [$width, $height];
        }

        // Too small to fill the box: keep its ratio, at the size the original allows.
        $factor = min($originalWidth / $width, $originalHeight / $height);

        return [max(1, (int) round($width * $factor)), max(1, (int) round($height * $factor))];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scale(ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        $factor = match (true) {
            $spec->width !== null && $spec->height !== null => min($spec->width / $originalWidth, $spec->height / $originalHeight),
            $spec->width !== null => $spec->width / $originalWidth,
            default => (int) $spec->height / $originalHeight,
        };

        return $this->resized($originalWidth, $originalHeight, $factor, $spec->upscale);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function contain(ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        return $this->resized($originalWidth, $originalHeight, min((int) $spec->width / $originalWidth, (int) $spec->height / $originalHeight), $spec->upscale);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resized(int $originalWidth, int $originalHeight, float $factor, bool $upscale): array
    {
        if ($factor >= 1 && ! $upscale) {
            return [$originalWidth, $originalHeight];
        }

        return [max(1, (int) round($originalWidth * $factor)), max(1, (int) round($originalHeight * $factor))];
    }
}
