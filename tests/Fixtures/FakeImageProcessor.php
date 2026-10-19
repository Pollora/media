<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use Pollora\Media\Domain\Contracts\ImageProcessor;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizeGeometry;

/**
 * Stands for the image editor of WordPress, which the unit tests do not have: it writes a file of the size
 * the geometry says, so the rest (names, metadata, URLs) is tested for real. The editor itself is checked on a WordPress.
 */
final class FakeImageProcessor implements ImageProcessor
{
    /** @var list<array{source: string, destination: string, size: string}> */
    public array $made = [];

    public function __construct(private readonly MediaStorage $storage, private readonly ?string $failing = null) {}

    public function make(string $sourceKey, string $destinationKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): array
    {
        if ($spec->name === $this->failing) {
            throw new \RuntimeException('The editor cannot write this size.');
        }

        [$width, $height] = (new SizeGeometry)->dimensions($spec, $originalWidth, $originalHeight);
        $this->storage->write($destinationKey, 'image '.$width.'x'.$height);
        $this->made[] = ['source' => $sourceKey, 'destination' => $destinationKey, 'size' => $spec->name];

        return ['width' => $width, 'height' => $height, 'mime' => 'image/webp', 'filesize' => 12];
    }
}
