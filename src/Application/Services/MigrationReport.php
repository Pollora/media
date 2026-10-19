<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

/**
 * What a migration did, or would do in a dry run.
 */
final readonly class MigrationReport
{
    /**
     * @param  list<string>  $copied
     * @param  list<string>  $skipped  Already on the target with the same size
     * @param  list<string>  $failed
     */
    public function __construct(
        public array $copied,
        public array $skipped,
        public array $failed,
        public bool $dryRun,
    ) {}
}
