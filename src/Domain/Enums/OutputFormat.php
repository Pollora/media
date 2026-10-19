<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Enums;

/**
 * The format a size is written in. WebP by default: read by every current browser and much lighter than JPEG.
 */
enum OutputFormat: string
{
    case Webp = 'webp';

    case Avif = 'avif';

    /** The format of the original. */
    case Original = 'original';

    public function defaultQuality(): int
    {
        return 80;
    }
}
