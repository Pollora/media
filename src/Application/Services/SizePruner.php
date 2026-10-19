<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Pollora\Media\Domain\Contracts\MediaStorage;

/**
 * Finds, and deletes, the sized files that no declared size stands for any more:
 * a size that was changed (its signature moved on) or removed.
 *
 * Only a file named the way the module names its sizes, next to an original that is
 * still there, is a candidate: whatever WordPress or a plugin made is left alone.
 */
final readonly class SizePruner
{
    private const array ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(
        private MediaStorage $storage,
        private ImageSizes $sizes,
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

            if ($spec === null || $this->sizes->names()->signature($original, $spec) !== $parsed['signature']) {
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
     * @param  array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}  $parsed
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
