<?php

declare(strict_types=1);

namespace Pollora\Media;

use Pollora\Media\Application\Services\ImageSizeDeclaration;
use Pollora\Media\Application\Services\ImageSizes;

/**
 * Declares image sizes for a WordPress theme or plugin that is not a Pollora
 * application: a classic theme, a plugin, a Bedrock site. It is the framework's
 * `ImageSize` facade for a project with no Laravel: the same calls, on the same
 * registry. The file names of the sizes are signed with a salt of WordPress.
 *
 * ```php
 * ImageSize::add('card')->cover(600, 400)->webp(75);
 * ImageSize::add('hero')->scale(width: 1920)->webp(70)->widths([640, 1024, 1440]);
 * ```
 */
final class ImageSize
{
    private static ?ImageSizes $registry = null;

    /**
     * Declares a size. Its options are chained: how it fits (`cover()`, `scale()`, `contain()`), its format
     * (`webp()`, `avif()`, `original()`), `widths()` for a `srcset`, `eager()`, `upscale()`.
     */
    public static function add(string $name): ImageSizeDeclaration
    {
        return self::registry()->add($name);
    }

    /**
     * The sizes declared so far.
     */
    public static function registry(): ImageSizes
    {
        return self::$registry ??= new ImageSizes(static fn (): string => \wp_salt('pollora/media'));
    }

    /**
     * Forgets every declaration. For tests.
     */
    public static function reset(): void
    {
        self::$registry = null;
    }
}
