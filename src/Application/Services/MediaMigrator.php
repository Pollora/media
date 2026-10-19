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
 */
final readonly class MediaMigrator
{
    public function __construct(
        private MediaStorage $from,
        private MediaStorage $to,
    ) {}

    /**
     * @param  (Closure(string, string): void)|null  $progress  Receives the path and `copy`, `skip` or `fail`
     */
    public function migrate(bool $dryRun = true, ?Closure $progress = null): MigrationReport
    {
        $copied = $skipped = $failed = [];

        $files = array_filter($this->from->list('', true), static fn (array $entry): bool => $entry['type'] === 'file');
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        foreach ($files as $file) {
            $path = $file['path'];

            try {
                if ($this->to->exists($path) && $this->to->size($path) === $file['size']) {
                    $skipped[] = $path;
                    $this->tell($progress, $path, 'skip');

                    continue;
                }

                if (! $dryRun) {
                    $this->to->write($path, $this->from->readStream($path));
                }

                $copied[] = $path;
                $this->tell($progress, $path, 'copy');
            } catch (Throwable) {
                $failed[] = $path;
                $this->tell($progress, $path, 'fail');
            }
        }

        return new MigrationReport($copied, $skipped, $failed, $dryRun);
    }

    private function tell(?Closure $progress, string $path, string $outcome): void
    {
        if ($progress instanceof Closure) {
            $progress($path, $outcome);
        }
    }
}
