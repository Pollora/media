<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Domain\Contracts\ImageProcessor;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Exceptions\ImageProcessingFailed;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizeGeometry;

/**
 * Makes a size with the image editor of WordPress, GD or Imagick, on the
 * `pollora://` paths: the editors read and write a stream as they are.
 */
final readonly class WordPressImageProcessor implements ImageProcessor
{
    public function __construct(private SizeGeometry $geometry = new SizeGeometry) {}

    public function make(string $sourceKey, string $destinationKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        $mime = match ($spec->format) {
            OutputFormat::Webp => 'image/webp',
            OutputFormat::Avif => 'image/avif',
            OutputFormat::Original => (string) \wp_check_filetype($sourceKey)['type'],
        };

        if ($mime === '' || ! \wp_image_editor_supports(['mime_type' => $mime])) {
            throw ImageProcessingFailed::unsupportedFormat($mime, $spec->name);
        }

        $editor = \wp_get_image_editor(StreamWrapper::ROOT.'/'.$sourceKey);

        if (\is_wp_error($editor)) {
            throw ImageProcessingFailed::because($editor->get_error_message());
        }

        [$width, $height] = $this->geometry->dimensions($spec, $originalWidth, $originalHeight);
        $region = $spec->fit === Fit::Cover
            ? $this->geometry->coverRegion($originalWidth, $originalHeight, (int) $spec->width, (int) $spec->height, $spec->anchor)
            : ['x' => 0, 'y' => 0, 'width' => $originalWidth, 'height' => $originalHeight];

        $editor->set_quality($spec->quality);
        $done = $editor->crop($region['x'], $region['y'], $region['width'], $region['height'], $width, $height);

        if (\is_wp_error($done)) {
            throw ImageProcessingFailed::because($done->get_error_message());
        }

        $saved = $editor->save(StreamWrapper::ROOT.'/'.$destinationKey, $mime);

        if (\is_wp_error($saved)) {
            throw ImageProcessingFailed::because($saved->get_error_message());
        }

        return ['width' => (int) $saved['width'], 'height' => (int) $saved['height'], 'mime' => (string) $saved['mime-type'], 'filesize' => (int) @filesize(StreamWrapper::ROOT.'/'.$destinationKey)];
    }
}
