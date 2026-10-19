<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Closure;
use Pollora\Media\Domain\Contracts\GenerationLock;
use Pollora\Media\Domain\Contracts\ImageProcessor;
use Pollora\Media\Domain\Contracts\LockHandle;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizeGeometry;
use Throwable;

/**
 * Makes a size when it is asked for and its file does not exist yet, at the exact path of its
 * URL, and answers with it. A request that comes back finds the file where the URL said, and
 * the web server or the CDN serves it without PHP: the URL never changes.
 *
 * Nothing is trusted from the URL. What it says is checked, first without asking the disk (the
 * name is one of a declared size, the signature is the one the site would have made, the
 * extension is an image's), then against the original (its dimensions are those the name
 * gives, it is not too big, it is not private). Only then is anything made, one process per
 * file and a ceiling of processes per server; a request that cannot get a place in time
 * is handed a size that exists, with no cache, rather than a broken image.
 */
final readonly class ThumbnailService
{
    private const array OUTPUT_EXTENSIONS = ['webp', 'avif', 'jpg', 'jpeg', 'png'];

    private const array ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    private const string IMMUTABLE = 'public, max-age=31536000, immutable';

    /**
     * @param  Closure(string): bool  $isPrivate  Whether the original, by path, is a private media
     */
    public function __construct(
        private MediaStorage $storage,
        private ImageSizes $sizes,
        private ImageProcessor $processor,
        private GenerationLock $lock,
        private ThumbnailLimits $limits,
        private Closure $isPrivate,
        private SizeGeometry $geometry = new SizeGeometry,
    ) {}

    /**
     * @param  string  $key  The path asked for, relative to the library
     */
    public function serve(string $key): ThumbnailResult
    {
        $key = ltrim($key, '/');

        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0") || str_contains($key, '//')) {
            return ThumbnailResult::notFound();
        }

        $parsed = $this->sizes->names()->parse($key);
        $spec = $parsed === null ? null : $this->lookup($parsed['name']);

        if ($parsed === null || ! $spec instanceof ImageSizeSpec || ! in_array($parsed['extension'], self::OUTPUT_EXTENSIONS, true)) {
            return ThumbnailResult::notFound();
        }

        $original = $this->originalFor($parsed, $spec);

        if ($original === null) {
            return ThumbnailResult::notFound();
        }

        try {
            return $this->produce($key, $original, $parsed, $spec);
        } catch (Throwable) {
            return new ThumbnailResult(500);
        }
    }

    /**
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}  $parsed
     */
    private function produce(string $key, string $original, array $parsed, ImageSizeSpec $spec): ThumbnailResult
    {
        if ($this->storage->exists($key)) {
            return $this->file($key, $parsed['extension']);
        }

        if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) === 'gif' || ! $this->storage->exists($original) || ($this->isPrivate)($original)) {
            return ThumbnailResult::notFound();
        }

        $fileLock = $this->lock->forFile($key, $this->limits->wait);

        if (! $fileLock instanceof LockHandle) {
            return $this->nearest($original, $parsed);
        }

        try {
            if ($this->storage->exists($key)) {
                return $this->file($key, $parsed['extension']);
            }

            $slot = $this->lock->slot($this->limits->concurrency, $this->limits->wait);

            if (! $slot instanceof LockHandle) {
                return $this->nearest($original, $parsed);
            }

            try {
                return $this->make($key, $original, $parsed, $spec);
            } finally {
                $slot->release();
            }
        } finally {
            $fileLock->release();
        }
    }

    /**
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}  $parsed
     */
    private function make(string $key, string $original, array $parsed, ImageSizeSpec $spec): ThumbnailResult
    {
        $bytes = stream_get_contents($this->storage->readStream($original), $this->limits->maxBytes + 1);

        if ($bytes === false || strlen($bytes) > $this->limits->maxBytes) {
            return ThumbnailResult::notFound();
        }

        $dimensions = @getimagesizefromstring($bytes);
        unset($bytes);

        if ($dimensions === false || $this->limits->maxPixels < $dimensions[0] * $dimensions[1]) {
            return ThumbnailResult::notFound();
        }

        [$width, $height] = $this->geometry->dimensions($spec, $dimensions[0], $dimensions[1]);

        if ($width !== $parsed['width'] || $height !== $parsed['height']) {
            return ThumbnailResult::notFound();
        }

        $this->processor->make($original, $key, $spec, $dimensions[0], $dimensions[1]);

        return $this->file($key, $parsed['extension']);
    }

    private function file(string $key, string $extension): ThumbnailResult
    {
        return new ThumbnailResult(200, $this->storage->readStream($key), $this->mime($extension), ['Cache-Control' => self::IMMUTABLE]);
    }

    /**
     * The size already made that stands in for the one that cannot be made now: the smallest that is at least as wide,
     * else the widest there is, else the original. It is not kept in any cache.
     *
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}  $parsed
     */
    private function nearest(string $original, array $parsed): ThumbnailResult
    {
        $directory = $parsed['directory'];
        $candidates = [];

        foreach ($this->storage->list($directory) as $entry) {
            if ($entry['type'] !== 'file') {
                continue;
            }

            $name = basename($entry['path']);

            if (preg_match('/^'.preg_quote($parsed['stem'], '/').'-(?:[a-z0-9_]+-)?(\d+)x(\d+)(?:-[0-9a-f]{12})?\.(jpe?g|png|webp|avif)$/', $name, $match) === 1) {
                $candidates[$entry['path']] = (int) $match[1];
            }
        }

        asort($candidates);
        $bigEnough = array_filter($candidates, fn (int $width): bool => $width >= $parsed['width']);
        $chosen = $bigEnough !== [] ? array_key_first($bigEnough) : ($candidates === [] ? $original : array_key_last($candidates));

        return new ThumbnailResult(200, $this->storage->readStream((string) $chosen), $this->mime(strtolower(pathinfo((string) $chosen, PATHINFO_EXTENSION))), ['Cache-Control' => 'no-store']);
    }

    /**
     * The original this name is of: the one, among the extensions an original can have, whose signature is the name's.
     *
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}  $parsed
     */
    private function originalFor(array $parsed, ImageSizeSpec $spec): ?string
    {
        $directory = $parsed['directory'] === '' ? '' : $parsed['directory'].'/';

        foreach (self::ORIGINAL_EXTENSIONS as $extension) {
            foreach (array_unique([$extension, strtoupper($extension)]) as $candidate) {
                $original = $directory.$parsed['stem'].'.'.$candidate;

                if (hash_equals($this->sizes->names()->signature($original, $spec), $parsed['signature']) && $this->extensionFits($spec, $candidate, $parsed['extension'])) {
                    return $original;
                }
            }
        }

        return null;
    }

    private function extensionFits(ImageSizeSpec $spec, string $originalExtension, string $outputExtension): bool
    {
        return match ($spec->format) {
            OutputFormat::Webp => $outputExtension === 'webp',
            OutputFormat::Avif => $outputExtension === 'avif',
            OutputFormat::Original => $outputExtension === strtolower($originalExtension),
        };
    }

    private function lookup(string $name): ?ImageSizeSpec
    {
        foreach ($this->sizes->all() as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }

            $variants = $this->sizes->variantsOf($spec);

            if (isset($variants[$name])) {
                return $variants[$name];
            }
        }

        return null;
    }

    private function mime(string $extension): string
    {
        return match ($extension) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
