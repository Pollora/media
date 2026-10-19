<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * What a declared size is as an imgproxy preset (an entry of IMGPROXY_PRESETS). The output format is
 * not in the preset: it is the extension of the URL.
 *
 * A cover is `fill-down` unless it may enlarge: imgproxy then crops an undersized result to keep the
 * ratio, which is the size the module advertises for an original too small to fill the box.
 *
 * @see https://docs.imgproxy.net/latest/usage/processing
 */
final class ImgproxyPresets
{
    public function definition(ImageSizeSpec $spec): string
    {
        $type = match ($spec->fit) {
            Fit::Cover => $spec->upscale ? 'fill' : 'fill-down',
            Fit::Scale, Fit::Contain => 'fit',
        };

        return sprintf('%s=resize:%s:%d:%d:%d/quality:%d', $spec->name, $type, $spec->width ?? 0, $spec->height ?? 0, (int) $spec->upscale, $spec->quality);
    }

    /**
     * Why imgproxy cannot serve a size by this name, or null when it can: a preset named `default` is
     * applied by imgproxy to every image, so a size of that name would change them all.
     */
    public function refusal(ImageSizeSpec $spec): ?string
    {
        return $spec->name === 'default'
            ? 'imgproxy applies a preset named "default" to every image: the size "default" would change them all. Give the size another name.'
            : null;
    }
}
