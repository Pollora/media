<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Closure;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\FocalPoint;

/**
 * Finds, and deletes, the sized files that no declared size stands for any more:
 * a size that was changed (its signature moved on) or removed, or a crop around a focal point that was moved.
 *
 * Only a file named the way the module names its sizes, next to an original that is
 * still there, is a candidate: whatever WordPress or a plugin made is left alone.
 */
final readonly class SizePruner
{
    private const array ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * @param  (Closure(string): ?FocalPoint)|null  $focalOf  The focal point of an original, by path. Without it, a file made around a point is never stale for its point alone
     */
    public function __construct(
        private MediaStorage $storage,
        private ImageSizes $sizes,
        private ?Closure $focalOf = null,
    ) {}

    /**
     * @return list<string> Paths relative to the library
     */
    public function stale(): array
    {
        $files = array_values(array_filter($this->storage->list('', true), static fn (array $entry): bool => $entry['type'] === 'file'));
        $paths = array_flip(array_column($files, 'path'));
        $declared = [];

        foreach ($this->sizes->all() as $name => $spec) {
            $declared[$name] = $spec;
            $declared += $this->sizes->variantsOf($spec);
        }

        $stale = [];

        foreach (array_keys($paths) as $path) {
            $parsed = $this->sizes->names()->parse((string) $path);

            if ($parsed === null) {
                continue;
            }

            $original = $this->originalOf($parsed, $paths);

            if ($original === null) {
                continue;
            }

            $spec = $declared[$parsed['name']] ?? null;

            if ($spec === null || ! hash_equals($this->sizes->names()->signature($original, $spec->focusedOn($parsed['focal'])), $parsed['signature']) || $this->movedOn($original, $parsed['focal'])) {
                $stale[] = (string) $path;
            }
        }

        sort($stale);

        return $stale;
    }

    /**
     * @return list<string> What was deleted, or would be in a dry run
     */
    public function prune(bool $dryRun = true): array
    {
        $stale = $this->stale();

        if (! $dryRun) {
            foreach ($stale as $path) {
                $this->storage->delete($path);
            }
        }

        return $stale;
    }

    /**
     * Whether a file was made around a focal point that is not the one of its image any more: the point was moved or removed.
     */
    private function movedOn(string $original, ?FocalPoint $made): bool
    {
        return $made instanceof FocalPoint && $this->focalOf instanceof Closure && ! $made->equals(($this->focalOf)($original));
    }

    /**
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, focal: FocalPoint|null, signature: string, extension: string}  $parsed
     * @param  array<int|string, int>  $paths
     */
    private function originalOf(array $parsed, array $paths): ?string
    {
        $directory = $parsed['directory'] === '' ? '' : $parsed['directory'].'/';

        foreach (self::ORIGINAL_EXTENSIONS as $extension) {
            foreach ([$extension, strtoupper($extension)] as $candidate) {
                if (isset($paths[$directory.$parsed['stem'].'.'.$candidate])) {
                    return $directory.$parsed['stem'].'.'.$candidate;
                }
            }
        }

        return null;
    }
}
