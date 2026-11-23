<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Closure;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Contracts\ImageProcessor;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\FocalPoint;
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

    /** What serves a media that has a focal point when the configured driver is a service that cannot: the files of the module */
    private readonly ThumbnailDriver $focalDriver;

    /** @var array<int, list<string>> Files made around the focal point of a media about to change it */
    private array $focalFiles = [];

    /**
     * @param  (Closure(int): bool)|null  $isPrivate  Whether an attachment is private: a private media never goes to a service that makes the sizes
     */
    public function __construct(
        private readonly ImageSizes $sizes,
        private readonly MediaConfig $config,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $processor,
        ?ThumbnailDriver $driver = null,
        private readonly ?Closure $isPrivate = null,
        private readonly ?FocalPoints $focalPoints = null,
    ) {
        $this->geometry = new SizeGeometry;
        $this->driver = $driver ?? new PolloraThumbnailDriver($sizes->names(), $config, $sizes);
        $this->focalDriver = $this->driver->makesFiles() ? $this->driver : new PolloraThumbnailDriver($sizes->names(), $config, $sizes);
    }

    public function register(): void
    {
        \add_filter('intermediate_image_sizes_advanced', [$this, 'withoutDeclared'], 100, 1);
        \add_filter('wp_generate_attachment_metadata', [$this, 'generate'], 20, 3);
        \add_filter('wp_update_attachment_metadata', [$this, 'remake'], 20, 2);
        \add_filter('image_downsize', [$this, 'downsize'], 10, 3);
        \add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 5, 5);

        if ($this->focalPoints instanceof FocalPoints) {
            \add_action('update_post_meta', [$this, 'focalWillChange'], 10, 3);
            \add_action('delete_post_meta', [$this, 'focalWillChange'], 10, 3);
            \add_action('added_post_meta', [$this, 'focalChanged'], 10, 3);
            \add_action('updated_post_meta', [$this, 'focalChanged'], 10, 3);
            \add_action('deleted_post_meta', [$this, 'focalChanged'], 10, 3);
        }
    }

    /**
     * The focal point of a media, which the crops of its covers follow.
     */
    public function focalOf(int $attachmentId): ?FocalPoint
    {
        return $this->focalPoints?->for($attachmentId);
    }

    /**
     * The focal point the sizes of a media follow: none for a private media that a service would make, which keeps
     * the media from the service and cannot be given a file the module may not make on demand for it.
     */
    private function focalForSizes(int $attachmentId): ?FocalPoint
    {
        return $this->isKeptFromService($attachmentId, $this->driver) ? null : $this->focalOf($attachmentId);
    }

    /**
     * Notes the files made around the focal point of a media, just before the point is changed or removed.
     */
    public function focalWillChange(mixed $metaId, mixed $attachmentId, mixed $metaKey): void
    {
        if ($metaKey !== FocalPoints::META || ! is_numeric($attachmentId)) {
            return;
        }

        $metadata = \wp_get_attachment_metadata((int) $attachmentId);
        $this->focalFiles[(int) $attachmentId] = is_array($metadata) ? $this->existingFiles($metadata, $this->focalOf((int) $attachmentId), true) : [];
    }

    /**
     * A new focal point is new file names: the files of the previous point are deleted (a request for one of
     * their URLs makes it again, the name saying the point), and the eager sizes are made around the new one.
     */
    public function focalChanged(mixed $metaId, mixed $attachmentId, mixed $metaKey): void
    {
        if ($metaKey !== FocalPoints::META || ! is_numeric($attachmentId)) {
            return;
        }

        $id = (int) $attachmentId;
        $this->focalPoints?->forget($id);
        $metadata = \wp_get_attachment_metadata($id);
        $previous = $this->focalFiles[$id] ?? [];
        unset($this->focalFiles[$id]);

        if (! is_array($metadata) || ! $this->isMakeable($metadata)) {
            return;
        }

        $current = $this->existingFiles($metadata, $this->focalOf($id), true);

        foreach (array_diff($previous, $current) as $file) {
            try {
                $this->storage->delete($file);
            } catch (Throwable) {
                continue;
            }
        }

        if ($this->sizes->isEmpty()) {
            return;
        }

        $remade = $this->withEagerSizes($metadata, $this->focalOf($id));

        if ($remade !== $metadata) {
            \wp_update_attachment_metadata($id, $remade);
        }
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

        return $this->withEagerSizes($metadata, $this->focalOf($attachmentId));
    }

    /**
     * Makes the eager sizes of an image whose original was replaced: cropped, rotated or flipped in the media
     * library, or put back by *Restore image*. WordPress writes the new original and its own sizes, then saves
     * the metadata without going through `wp_generate_attachment_metadata`, so `generate()` never sees it.
     * The files of the previous original stay: WordPress keeps it as the backup that *Restore image* goes back to.
     *
     * @param  mixed  $metadata  What is about to be saved
     */
    public function remake(mixed $metadata, int $attachmentId): mixed
    {
        if (! is_array($metadata) || $this->sizes->isEmpty() || ! $this->isImage($metadata)) {
            return $metadata;
        }

        $stored = \wp_get_attachment_metadata($attachmentId);
        $previous = is_array($stored) ? (string) ($stored['file'] ?? '') : '';

        if ($previous === '' || $previous === (string) $metadata['file']) {
            return $metadata;
        }

        return $this->withEagerSizes($metadata, $this->focalOf($attachmentId));
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

        $spec = $this->known()[$size]->focusedOn($this->focalForSizes($attachmentId));
        $driver = $this->driverFor($spec);

        if ($driver->refusal($spec) !== null || $this->isKeptFromService($attachmentId, $driver)) {
            return false;
        }

        if ($this->isGif($metadata)) {
            return [$this->url((string) $metadata['file']), (int) $metadata['width'], (int) $metadata['height'], false];
        }

        [$width, $height] = $this->geometry->dimensions($spec, (int) $metadata['width'], (int) $metadata['height']);

        return [$driver->url((string) $metadata['file'], $spec, (int) $metadata['width'], (int) $metadata['height']), $width, $height, true];
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
        if ($sources === false || ! $this->isImage($imageMeta)) {
            return $sources;
        }

        $name = $this->driver->sizeOf($imageSrc) ?? $this->focalDriver->sizeOf($imageSrc);
        $spec = $name === null ? null : ($this->known()[$name] ?? null);
        $base = $spec === null ? null : ($this->sizes->get($spec->name) ?? $this->parentOf($spec));

        if (! $base instanceof ImageSizeSpec || $base->widths === []) {
            return $sources;
        }

        $base = $base->focusedOn($this->focalForSizes($attachmentId));
        $driver = $this->driverFor($base);

        if ($this->isKeptFromService($attachmentId, $driver)) {
            return $sources;
        }

        $candidates = [$base, ...array_values($this->sizes->variantsOf($base))];
        $built = [];

        foreach ($candidates as $candidate) {
            [$width] = $this->geometry->dimensions($candidate, (int) $imageMeta['width'], (int) $imageMeta['height']);
            $built[$width] = ['url' => $driver->url((string) $imageMeta['file'], $candidate, (int) $imageMeta['width'], (int) $imageMeta['height']), 'descriptor' => 'w', 'value' => $width];
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
    public function make(array $metadata, ImageSizeSpec $spec, ?FocalPoint $focal = null): ?array
    {
        if ($this->isGif($metadata)) {
            return null;
        }

        $spec = $spec->focusedOn($focal);

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
    public function fileFor(array $metadata, ImageSizeSpec $spec, ?FocalPoint $focal = null): string
    {
        return $this->fileOf($metadata, $spec->focusedOn($focal));
    }

    /**
     * The files of the declared sizes of an original that are on the disk, made at upload or on demand.
     * WordPress does not know the ones made on demand: they follow the media when it is deleted or changes visibility.
     *
     * A media with a focal point has the files of its covers made around it, and may keep those made before the
     * point was set: both go with it.
     *
     * @param  array<string, mixed>  $metadata
     * @param  bool  $onlyFocal  Only the files of the covers made around the focal point
     * @return list<string>
     */
    public function existingFiles(array $metadata, ?FocalPoint $focal = null, bool $onlyFocal = false): array
    {
        if ($this->sizes->isEmpty() || ! $this->isMakeable($metadata)) {
            return [];
        }

        $files = [];

        foreach ($this->known() as $spec) {
            $focused = $spec->focusedOn($focal);
            $candidates = $focused->focal instanceof FocalPoint ? [$this->fileOf($metadata, $focused)] : [];

            if (! $onlyFocal) {
                $candidates[] = $this->fileOf($metadata, $spec);
            }

            foreach ($candidates as $file) {
                try {
                    if ($this->storage->exists($file)) {
                        $files[] = $file;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return array_values(array_unique($files));
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

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withEagerSizes(array $metadata, ?FocalPoint $focal = null): array
    {
        foreach ($this->known() as $spec) {
            if (! $spec->eager || ! $this->driverFor($spec->focusedOn($focal))->makesFiles()) {
                continue;
            }

            $entry = $this->make($metadata, $spec, $focal);

            if ($entry !== null) {
                $metadata['sizes'][$spec->name] = $entry;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, ImageSizeSpec>
     */
    private function known(): array
    {
        return $this->sizes->withVariants();
    }

    /**
     * Whether the sizes of this media must not be asked of a service that makes them: a private media
     * would be fetched, and served, by a service that has no notion of who may see it.
     */
    private function isKeptFromService(int $attachmentId, ThumbnailDriver $driver): bool
    {
        return ! $driver->makesFiles() && $this->isPrivate instanceof Closure && ($this->isPrivate)($attachmentId);
    }

    /**
     * What serves a size of an image: the configured driver, except for a cover around a focal point. A focal point is
     * per image, so it cannot live in an imgproxy preset or a Bunny class, which are meant to be the only way to
     * transform the images: that crop is a file of the module, made on demand, whatever the driver.
     */
    private function driverFor(ImageSizeSpec $spec): ThumbnailDriver
    {
        return $spec->fit === Fit::Cover && $spec->focal instanceof FocalPoint ? $this->focalDriver : $this->driver;
    }

    private function parentOf(ImageSizeSpec $variant): ?ImageSizeSpec
    {
        return preg_match('/^(.+)-\d+w$/D', $variant->name, $match) === 1 ? $this->sizes->get($match[1]) : null;
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
