<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

/**
 * Stands for a class of a plugin whose code runs when it is unserialized, the way object injection works.
 */
final class SerializedGadget
{
    public static bool $woken = false;

    public function __construct(public string $url) {}

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
