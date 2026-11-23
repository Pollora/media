<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Closure;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Exceptions\InvalidMediaConfigurationException;
use Pollora\Media\Domain\Models\FocalPoint;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * The name of the file a size makes: `photo-card-600x400-{signature}.webp`.
 *
 * The signature is an HMAC of the original and of the parameters of the size,
 * cut to 12 characters. It protects (only a URL the site produced is a name it
 * would generate) and it fingerprints: changing a size changes its name, so a
 * cache never holds a stale file.
 *
 * A cover made around the focal point of an image says it: `photo-card-600x400-f0250_0750-{signature}.webp`
 * (thousandths from the left and from the top). The point is in the signature too, so a name is only valid
 * with the point it states, and the file of a request is made from the name alone, with no lookup.
 */
final readonly class SizedFileName
{
    private const int SIGNATURE_LENGTH = 12;

    private const string PATTERN = '#^(?:(?<directory>.*)/)?(?<stem>.+?)-(?<name>[a-z0-9_]+(?:-\d+w)?)-(?<width>\d+)x(?<height>\d+)(?:-f(?<fx>\d{4})_(?<fy>\d{4}))?-(?<signature>[0-9a-f]{12})\.(?<extension>[a-z0-9]+)$#D';

    /**
     * @param  Closure(): string  $key  The secret the signature is made with: the application key, or a WordPress salt
     */
    public function __construct(private Closure $key) {}

    /**
     * @param  string  $originalKey  Path of the original, relative to the library
     *
     * @throws InvalidMediaConfigurationException When the signing key is empty
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
            '%s%s-%s-%dx%d%s-%s.%s',
            $directory,
            $info['filename'],
            $spec->name,
            $width,
            $height,
            $spec->focal instanceof FocalPoint ? '-'.$spec->focal->token() : '',
            $this->signature($originalKey, $spec),
            $extension,
        );
    }

    /**
     * @throws InvalidMediaConfigurationException When the signing key is empty
     */
    public function signature(string $originalKey, ImageSizeSpec $spec): string
    {
        return substr(hash_hmac('sha256', $originalKey.'|'.$spec->name.'|'.$spec->fingerprint(), $this->secret()), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * The secret, resolved when a signature is made (the application key may not be known before).
     *
     * @throws InvalidMediaConfigurationException When the key is empty: the signature would protect nothing
     */
    private function secret(): string
    {
        $secret = (string) ($this->key)();

        if (trim($secret) === '') {
            throw InvalidMediaConfigurationException::emptySigningKey();
        }

        return $secret;
    }

    /**
     * @return array{directory: string, stem: string, name: string, width: int, height: int, focal: FocalPoint|null, signature: string, extension: string}|null
     */
    public function parse(string $file): ?array
    {
        if (preg_match(self::PATTERN, $file, $match) !== 1) {
            return null;
        }

        $focal = null;

        if ($match['fx'] !== '') {
            $focal = FocalPoint::fromThousandths((int) $match['fx'], (int) $match['fy']);

            if (! $focal instanceof FocalPoint) {
                return null;
            }
        }

        return [
            'directory' => $match['directory'],
            'stem' => $match['stem'],
            'name' => $match['name'],
            'width' => (int) $match['width'],
            'height' => (int) $match['height'],
            'focal' => $focal,
            'signature' => $match['signature'],
            'extension' => $match['extension'],
        ];
    }
}
