<?php

declare(strict_types=1);

namespace Pollora\Media;

use League\Flysystem\FilesystemOperator;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\Flysystem\FlysystemMediaStorage;
use Pollora\Media\Infrastructure\WordPress\ConstantsConfiguration;
use Pollora\Media\Infrastructure\WordPress\WordPressMedia;

/**
 * Puts the media library on a Flysystem disk for a WordPress that is not a
 * Pollora application: a classic theme, a plugin, a Bedrock site. In Pollora,
 * the framework does it for you from `config/media.php`.
 *
 * ```php
 * // wp-config.php, then an mu-plugin or the first plugin loaded
 * define('POLLORA_MEDIA_DRIVER', 's3');
 * define('POLLORA_MEDIA_BUCKET', 'client-media');
 * define('POLLORA_MEDIA_URL', 'https://media.client.fr');
 *
 * \Pollora\Media\Media::register();
 * ```
 */
final class Media
{
    /**
     * Registers the `pollora://` wrapper and the hooks. Call it before WordPress builds its upload directory:
     * from a must-use plugin, or the first plugin loaded.
     */
    public static function register(?FilesystemOperator $filesystem = null, ?MediaConfig $config = null): WordPressMedia
    {
        $constants = new ConstantsConfiguration;

        $media = new WordPressMedia(
            new FlysystemMediaStorage($filesystem ?? $constants->filesystem()),
            $config ?? $constants->config(),
            new Action,
            limits: $constants->thumbnailLimits(),
            thumbnails: $constants->thumbnailDriver(),
        );
        $media->register();

        return $media;
    }
}
