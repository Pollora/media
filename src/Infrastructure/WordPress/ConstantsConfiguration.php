<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Domain\Exceptions\InvalidMediaConfiguration;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;

/**
 * The default adapter, for WordPress without Laravel: a classic theme, a
 * plugin, a Bedrock site. Everything is set in `wp-config.php`, with
 * constants; the `pollora/media/filesystem` filter covers a service they
 * cannot describe.
 *
 * Without any constant, the media stay where WordPress puts them.
 *
 * | Constant                          | Meaning                                         |
 * |-----------------------------------|-------------------------------------------------|
 * | `POLLORA_MEDIA_URL`               | public URL of the library                       |
 * | `POLLORA_MEDIA_LEGACY_URLS`       | old base URLs to rewrite (array, or comma list) |
 * | `POLLORA_MEDIA_CACHE_CONTROL`     | `Cache-Control` set on write                    |
 * | `POLLORA_MEDIA_PRIVATE_EXPIRY`    | life of a signed URL                            |
 * | `POLLORA_MEDIA_ASYNC_THUMBNAILS`  | generate sizes after the upload                 |
 * | `POLLORA_MEDIA_THUMBNAILS_MAX_PIXELS`, `_MAX_BYTES`, `_CONCURRENCY`, `_WAIT` | limits of a size made on demand |
 * | `POLLORA_MEDIA_THUMBNAILS_DRIVER` | who makes the sizes: `pollora` (default), `bunny` or `imgproxy` |
 * | `POLLORA_MEDIA_BUNNY_PULL_ZONE`, `_API_KEY` | pull zone and access key, to export the classes only |
 * | `POLLORA_MEDIA_IMGPROXY_URL`, `_KEY`, `_SALT`, `_SIGNATURE_SIZE`, `_SOURCE` | imgproxy: URL, hexadecimal key and salt, signature size (32), source `url` or `s3` |
 * | `POLLORA_MEDIA_DRIVER`            | `local` (default) or `s3`                       |
 * | `POLLORA_MEDIA_ROOT`              | local folder of the library                     |
 * | `POLLORA_MEDIA_BUCKET`, `_REGION`, `_KEY`, `_SECRET`, `_ENDPOINT`, `_PATH_STYLE` | S3 and compatible services |
 */
final class ConstantsConfiguration
{
    /** @var Closure(string): mixed */
    private readonly Closure $constant;

    /**
     * @param  (Closure(string): mixed)|null  $constant  Reads a constant by name; null when it is not defined
     */
    public function __construct(?Closure $constant = null)
    {
        $this->constant = $constant ?? static fn (string $name): mixed => defined($name) ? constant($name) : null;
    }

    public function config(): MediaConfig
    {
        $legacy = $this->read('POLLORA_MEDIA_LEGACY_URLS') ?? [];
        $legacy = is_string($legacy) ? array_map(trim(...), explode(',', $legacy)) : (array) $legacy;

        return new MediaConfig(
            (string) ($this->read('POLLORA_MEDIA_URL') ?? rtrim((string) $this->read('WP_CONTENT_URL'), '/').'/uploads'),
            array_values(array_filter(array_map(strval(...), $legacy), static fn (string $url): bool => $url !== '')),
            (string) ($this->read('POLLORA_MEDIA_CACHE_CONTROL') ?? 'public, max-age=31536000, immutable'),
            (string) ($this->read('POLLORA_MEDIA_PRIVATE_EXPIRY') ?? '+6 hours'),
            (bool) ($this->read('POLLORA_MEDIA_ASYNC_THUMBNAILS') ?? false),
        );
    }

    public function thumbnailLimits(): ThumbnailLimits
    {
        $defaults = new ThumbnailLimits;

        return new ThumbnailLimits(
            (int) ($this->read('POLLORA_MEDIA_THUMBNAILS_MAX_PIXELS') ?? $defaults->maxPixels),
            (int) ($this->read('POLLORA_MEDIA_THUMBNAILS_MAX_BYTES') ?? $defaults->maxBytes),
            (int) ($this->read('POLLORA_MEDIA_THUMBNAILS_CONCURRENCY') ?? $defaults->concurrency),
            (int) ($this->read('POLLORA_MEDIA_THUMBNAILS_WAIT') ?? $defaults->wait),
        );
    }

    public function thumbnailDriver(): ThumbnailDriverSettings
    {
        $source = (string) ($this->read('POLLORA_MEDIA_IMGPROXY_SOURCE') ?? 'url');
        $bucket = $this->read('POLLORA_MEDIA_BUCKET');

        return new ThumbnailDriverSettings(
            (string) ($this->read('POLLORA_MEDIA_THUMBNAILS_DRIVER') ?? ThumbnailDriverSettings::POLLORA),
            $this->optional('POLLORA_MEDIA_BUNNY_PULL_ZONE'),
            $this->optional('POLLORA_MEDIA_BUNNY_API_KEY'),
            $this->optional('POLLORA_MEDIA_IMGPROXY_URL'),
            $this->optional('POLLORA_MEDIA_IMGPROXY_KEY'),
            $this->optional('POLLORA_MEDIA_IMGPROXY_SALT'),
            (int) ($this->read('POLLORA_MEDIA_IMGPROXY_SIGNATURE_SIZE') ?? 32),
            $source,
            $source === 's3' && is_string($bucket) && $bucket !== '' ? $bucket : null,
        );
    }

    public function localRoot(): string
    {
        return (string) ($this->read('POLLORA_MEDIA_ROOT') ?? rtrim((string) $this->read('WP_CONTENT_DIR'), '/').'/uploads');
    }

    /**
     * @throws InvalidMediaConfiguration When the driver is unknown or lacks what it needs
     */
    public function filesystem(): FilesystemOperator
    {
        $provided = \apply_filters('pollora/media/filesystem', null);

        if ($provided instanceof FilesystemOperator) {
            return $provided;
        }

        return match ((string) ($this->read('POLLORA_MEDIA_DRIVER') ?? 'local')) {
            'local' => new Filesystem(new LocalFilesystemAdapter($this->localRoot())),
            's3' => $this->s3(),
            default => throw InvalidMediaConfiguration::unknownDriver($this->read('POLLORA_MEDIA_DRIVER')),
        };
    }

    private function s3(): FilesystemOperator
    {
        $bucket = $this->read('POLLORA_MEDIA_BUCKET');

        if (! is_string($bucket) || $bucket === '') {
            throw InvalidMediaConfiguration::missingBucket();
        }

        $adapter = 'League\Flysystem\AwsS3V3\AwsS3V3Adapter';
        $client = 'Aws\S3\S3Client';

        if (! class_exists($adapter) || ! class_exists($client)) {
            throw InvalidMediaConfiguration::missingS3Package();
        }

        $options = array_filter([
            'version' => 'latest',
            'region' => $this->read('POLLORA_MEDIA_REGION'),
            'endpoint' => $this->read('POLLORA_MEDIA_ENDPOINT'),
            'use_path_style_endpoint' => $this->read('POLLORA_MEDIA_PATH_STYLE') === null ? null : (bool) $this->read('POLLORA_MEDIA_PATH_STYLE'),
            'credentials' => $this->read('POLLORA_MEDIA_KEY') === null ? null : [
                'key' => (string) $this->read('POLLORA_MEDIA_KEY'),
                'secret' => (string) $this->read('POLLORA_MEDIA_SECRET'),
            ],
        ], static fn (mixed $value): bool => $value !== null);

        return new Filesystem(new $adapter(new $client($options), $bucket));
    }

    private function read(string $name): mixed
    {
        return ($this->constant)($name);
    }

    private function optional(string $name): ?string
    {
        $value = $this->read($name);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
