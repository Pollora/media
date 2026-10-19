<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

/**
 * What keeps the generation of sizes on demand in check: one process per file, and a ceiling on
 * how many run at once on a server.
 */
interface GenerationLock
{
    /**
     * One of at most `$max` places to generate in, or null when none is free after `$waitSeconds`.
     */
    public function slot(int $max, int $waitSeconds): ?LockHandle;

    /**
     * The right to generate one file, or null when another process holds it after `$waitSeconds`.
     */
    public function forFile(string $key, int $waitSeconds): ?LockHandle;
}
