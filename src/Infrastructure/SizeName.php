<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

/**
 * What a declared size may be called, and so what a class or a preset of a service is called:
 * lowercase letters, digits and underscores, and the `-640w` of an extra width.
 */
final class SizeName
{
    public const string PATTERN = '/^[a-z0-9_]+(?:-\d+w)?$/';
}
