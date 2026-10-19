<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

/**
 * The first bytes of a PNG, enough for getimagesizefromstring() to read its dimensions: the tests need an
 * original that says it is 3000 x 2000 without being one.
 */
final class PngHeader
{
    public static function bytes(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }
}
