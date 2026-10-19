<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

/**
 * A lock held: released when the work it guards is done.
 */
interface LockHandle
{
    public function release(): void;
}
