<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Closure;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * The name of the file a size makes: `photo-card-600x400-{signature}.webp`.
 *
 * The signature is an HMAC of the original and of the parameters of the size,
 * cut to 12 characters. It protects (only a URL the site produced is a name it
 * would generate) and it fingerprints: changing a size changes its name, so a
 * cache never holds a stale file.
 */
final readonly class SizedFileName
{
    private const int SIGNATURE_LENGTH = 12;

    private const string PATTERN = '#^(?:(?<directory>.*)/)?(?<stem>.+?)-(?<name>[a-z0-9_]+(?:-\d+w)?)-(?<width>\d+)x(?<height>\d+)-(?<signature>[0-9a-f]{12})\.(?<extension>[a-z0-9]+)$#';

    /**
     * @param  Closure(): string  $key  The secret the signature is made with: the application key, or a WordPress salt
     */
    public function __construct(private Closure $key) {}

    /**
     * @param  string  $originalKey  Path of the original, relative to the library
     */
    public function for(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string
    {
        [$width, $height] = (new SizeGeometry)->dimensions($spec, $originalWidth, $originalHeight);
        $info = pathinfo($originalKey);
        $directory = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $extension = match ($spec->format) {
            OutputFormat::Webp => 'webp',
            OutputFormat::Avif => 'avif',
            OutputFormat::Original => strtolower($info['extension'] ?? 'jpg'),
        };

        return sprintf(
            '%s%s-%s-%dx%d-%s.%s',
            $directory,
            $info['filename'],
            $spec->name,
            $width,
            $height,
            $this->signature($originalKey, $spec),
            $extension,
        );
    }

    public function signature(string $originalKey, ImageSizeSpec $spec): string
    {
        return substr(hash_hmac('sha256', $originalKey.'|'.$spec->name.'|'.$spec->fingerprint(), ($this->key)()), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * @return array{directory: string, stem: string, name: string, width: int, height: int, signature: string, extension: string}|null
     */
    public function parse(string $file): ?array
    {
        if (preg_match(self::PATTERN, $file, $match) !== 1) {
            return null;
        }

        return [
            'directory' => $match['directory'],
            'stem' => $match['stem'],
            'name' => $match['name'],
            'width' => (int) $match['width'],
            'height' => (int) $match['height'],
            'signature' => $match['signature'],
            'extension' => $match['extension'],
        ];
    }
}
