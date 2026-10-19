<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Contracts\ImageProcessor;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\SizeGeometry;
use Pollora\Media\Infrastructure\PolloraThumbnailDriver;
use Throwable;

/**
 * Puts the declared image sizes into WordPress: the files are made when an image
 * is uploaded, and the URL of a size, with its width, its height and its `srcset`,
 * is worked out from the metadata of the original alone: whether the file exists
 * or not, the URL is the same.
 *
 * The sizes WordPress registers itself (`add_image_size()`, the core ones, a plugin's) are left
 * as they are, with the names WordPress gives them.
 */
final class DeclaredSizes
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    private readonly SizeGeometry $geometry;

    private readonly ThumbnailDriver $driver;

    public function __construct(
        private readonly ImageSizes $sizes,
        private readonly MediaConfig $config,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $processor,
        ?ThumbnailDriver $driver = null,
    ) {
        $this->geometry = new SizeGeometry;
        $this->driver = $driver ?? new PolloraThumbnailDriver($sizes->names(), $config);
    }

    public function register(): void
    {
        \add_filter('intermediate_image_sizes_advanced', [$this, 'withoutDeclared'], 100, 1);
        \add_filter('wp_generate_attachment_metadata', [$this, 'generate'], 20, 3);
        \add_filter('image_downsize', [$this, 'downsize'], 10, 3);
        \add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 5, 5);
    }

    /**
     * @param  array<string, mixed>  $wordpressSizes
     * @return array<string, mixed>
     */
    public function withoutDeclared(array $wordpressSizes): array
    {
        return array_diff_key($wordpressSizes, $this->known());
    }

    /**
     * Makes the eager sizes of an image just uploaded, and records them in its metadata. The others are made
     * when they are first asked for (the fallback route), or by `thumbs warm`.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function generate(array $metadata, int $attachmentId, string $context = 'create'): array
    {
        if ($context !== 'create' || $this->sizes->isEmpty() || ! $this->isImage($metadata)) {
            return $metadata;
        }

        foreach ($this->known() as $spec) {
            if (! $spec->eager || ! $this->driver->makesFiles()) {
                continue;
            }

            $entry = $this->make($metadata, $spec);

            if ($entry !== null) {
                $metadata['sizes'][$spec->name] = $entry;
            }
        }

        return $metadata;
    }

    /**
     * The URL, width and height of a declared size, for `wp_get_attachment_image_src()`.
     *
     * @return array{0: string, 1: int, 2: int, 3: bool}|mixed
     */
    public function downsize(mixed $short, int $attachmentId, mixed $size): mixed
    {
        if ($short !== false || ! is_string($size) || ! isset($this->known()[$size])) {
            return $short;
        }

        $metadata = \wp_get_attachment_metadata($attachmentId);

        if (! is_array($metadata) || ! $this->isImage($metadata)) {
            return false;
        }

        $spec = $this->known()[$size];

        if ($this->isGif($metadata)) {
            return [$this->url((string) $metadata['file']), (int) $metadata['width'], (int) $metadata['height'], false];
        }

        [$width, $height] = $this->geometry->dimensions($spec, (int) $metadata['width'], (int) $metadata['height']);

        return [$this->driver->url((string) $metadata['file'], $spec, (int) $metadata['width'], (int) $metadata['height']), $width, $height, true];
    }

    /**
     * The `srcset` of a size with extra widths: each is a bounded size, its URL built, not read.
     *
     * @param  array<int, array<string, mixed>>|false  $sources
     * @param  array<int, int>  $sizeArray
     * @param  array<string, mixed>  $imageMeta
     * @return array<int, array<string, mixed>>|false
     */
    public function srcset(array|false $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array|false
    {
        $prefix = $this->config->url.'/';

        if ($sources === false || ! str_starts_with($imageSrc, $prefix) || ! $this->isImage($imageMeta)) {
            return $sources;
        }

        $parsed = $this->sizes->names()->parse(substr($imageSrc, strlen($prefix)));
        $spec = $parsed === null ? null : ($this->known()[$parsed['name']] ?? null);
        $base = $spec === null ? null : ($this->sizes->get($spec->name) ?? $this->parentOf($spec));

        if (! $base instanceof ImageSizeSpec || $base->widths === []) {
            return $sources;
        }

        $candidates = [$base, ...array_values($this->sizes->variantsOf($base))];
        $built = [];

        foreach ($candidates as $candidate) {
            [$width] = $this->geometry->dimensions($candidate, (int) $imageMeta['width'], (int) $imageMeta['height']);
            $built[$width] = ['url' => $this->driver->url((string) $imageMeta['file'], $candidate, (int) $imageMeta['width'], (int) $imageMeta['height']), 'descriptor' => 'w', 'value' => $width];
        }

        ksort($built);

        return $built;
    }

    /**
     * Makes one size of the original the metadata describes, unless its file is already there.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null The metadata entry of the size, or null when it could not be made
     */
    public function make(array $metadata, ImageSizeSpec $spec): ?array
    {
        if ($this->isGif($metadata)) {
            return null;
        }

        $original = (string) $metadata['file'];
        $destination = $this->fileOf($metadata, $spec);
        [$width, $height] = $this->geometry->dimensions($spec, (int) $metadata['width'], (int) $metadata['height']);

        try {
            if ($this->storage->exists($destination)) {
                return ['file' => basename($destination), 'width' => $width, 'height' => $height, 'mime-type' => $this->mime($destination), 'filesize' => $this->storage->size($destination)];
            }

            $made = $this->processor->make($original, $destination, $spec, (int) $metadata['width'], (int) $metadata['height']);
        } catch (Throwable $throwable) {
            \error_log(sprintf('pollora/media: size "%s" of %s was not made: %s', $spec->name, $original, $throwable->getMessage()));

            return null;
        }

        return ['file' => basename($destination), 'width' => $made['width'], 'height' => $made['height'], 'mime-type' => $made['mime'], 'filesize' => $made['filesize']];
    }

    /**
     * The path of the file a size makes of an original, relative to the library.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function fileFor(array $metadata, ImageSizeSpec $spec): string
    {
        return $this->fileOf($metadata, $spec);
    }

    /**
     * The files of the declared sizes of an original that are on the disk, made at upload or on demand.
     * WordPress does not know the ones made on demand: they follow the media when it is deleted or changes visibility.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    public function existingFiles(array $metadata): array
    {
        if ($this->sizes->isEmpty() || ! $this->isMakeable($metadata)) {
            return [];
        }

        $files = [];

        foreach ($this->known() as $spec) {
            $file = $this->fileOf($metadata, $spec);

            try {
                if ($this->storage->exists($file)) {
                    $files[] = $file;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $files;
    }

    /**
     * Every size to make or to serve: the declared ones, and the bounded size of each extra width.
     *
     * @return array<string, ImageSizeSpec>
     */
    public function specs(): array
    {
        return $this->known();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function isMakeable(array $metadata): bool
    {
        return $this->isImage($metadata) && ! $this->isGif($metadata);
    }

    private function known(): array
    {
        $known = [];

        foreach ($this->sizes->all() as $name => $spec) {
            $known[$name] = $spec;
            $known += $this->sizes->variantsOf($spec);
        }

        return $known;
    }

    private function parentOf(ImageSizeSpec $variant): ?ImageSizeSpec
    {
        return preg_match('/^(.+)-\d+w$/', $variant->name, $match) === 1 ? $this->sizes->get($match[1]) : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fileOf(array $metadata, ImageSizeSpec $spec): string
    {
        return $this->sizes->names()->for((string) $metadata['file'], $spec, (int) $metadata['width'], (int) $metadata['height']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function isImage(array $metadata): bool
    {
        $file = (string) ($metadata['file'] ?? '');
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return $file !== '' && isset($metadata['width'], $metadata['height']) && (in_array($extension, self::IMAGE_EXTENSIONS, true) || $extension === 'gif');
    }

    /**
     * A GIF may be animated, and a resize would lose it: its sizes are the original.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isGif(array $metadata): bool
    {
        return strtolower(pathinfo((string) $metadata['file'], PATHINFO_EXTENSION)) === 'gif';
    }

    private function url(string $file): string
    {
        return $this->config->url.'/'.ltrim($file, '/');
    }

    private function mime(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
