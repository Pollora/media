<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Closure;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Throwable;

/**
 * Copies the media library from a storage to another, file by file, keeping
 * every path. The source is never touched. A file already on the target with
 * the same size is skipped, so a run can be repeated after an interruption.
 *
 * Each file keeps the visibility it has on the source, and carries the cache
 * header when one is given. Folders that must not leave the source (protected
 * downloads, logs, form attachments) are left out with `exclude` patterns.
 */
final readonly class MediaMigrator
{
    public function __construct(
        private MediaStorage $from,
        private MediaStorage $to,
        private ?string $cacheControl = null,
    ) {}

    /**
     * @param  (Closure(string, string): void)|null  $progress  Receives the path and `copy`, `skip`, `exclude` or `fail`
     * @param  list<string>  $exclude  Patterns of the paths to leave out (`woocommerce_uploads/*`, `*.zip`): `*` matches across folders
     */
    public function migrate(bool $dryRun = true, ?Closure $progress = null, array $exclude = []): MigrationReport
    {
        $copied = $skipped = $failed = $excluded = [];

        $files = array_filter($this->from->list('', true), static fn (array $entry): bool => $entry['type'] === 'file');
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        foreach ($files as $file) {
            $path = $file['path'];

            if ($this->isExcluded($path, $exclude)) {
                $excluded[] = $path;
                $this->tell($progress, $path, 'exclude');

                continue;
            }

            try {
                if ($this->to->exists($path) && $this->to->size($path) === $file['size']) {
                    $skipped[] = $path;
                    $this->tell($progress, $path, 'skip');

                    continue;
                }

                if (! $dryRun) {
                    $this->to->write($path, $this->from->readStream($path), $this->writeOptions($path));
                }

                $copied[] = $path;
                $this->tell($progress, $path, 'copy');
            } catch (Throwable) {
                $failed[] = $path;
                $this->tell($progress, $path, 'fail');
            }
        }

        return new MigrationReport($copied, $skipped, $failed, $dryRun, $excluded);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeOptions(string $path): array
    {
        $options = ['visibility' => $this->from->visibility($path)];

        if ($this->cacheControl !== null) {
            $options['CacheControl'] = $this->cacheControl;
        }

        return $options;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function isExcluded(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function tell(?Closure $progress, string $path, string $outcome): void
    {
        if ($progress instanceof Closure) {
            $progress($path, $outcome);
        }
    }
}
