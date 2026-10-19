<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure;

use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\ImgproxyPresets;
use Pollora\Media\Domain\Services\ImgproxySigner;

/**
 * imgproxy in presets-only mode: the URL of a size is signed and names a preset, whatever the
 * visitor asks (`{base}/{signature}/card/plain/{source}@webp`). The preset is defined at imgproxy
 * (`thumbs export`), not here: no file is made.
 *
 * @see https://docs.imgproxy.net/latest/usage/presets
 */
final readonly class ImgproxyThumbnailDriver implements ThumbnailDriver
{
    /**
     * @param  string  $baseUrl  URL of imgproxy, without a trailing slash
     * @param  string|null  $bucket  When set, the source is the object of this bucket (`s3://`), not the URL of the library
     */
    public function __construct(
        private ImgproxySigner $signer,
        private MediaConfig $config,
        private string $baseUrl,
        private ?string $bucket = null,
    ) {}

    public function url(string $originalKey, ImageSizeSpec $spec, int $originalWidth, int $originalHeight): string
    {
        $key = ltrim($originalKey, '/');
        $source = $this->bucket === null ? $this->config->url.'/'.$key : 's3://'.$this->bucket.'/'.$key;
        $extension = match ($spec->format) {
            OutputFormat::Webp => '@webp',
            OutputFormat::Avif => '@avif',
            OutputFormat::Original => '',
        };
        $path = '/'.$spec->name.'/plain/'.strtr($source, ['%' => '%25', '?' => '%3F', '@' => '%40']).$extension;

        return $this->baseUrl.'/'.$this->signer->sign($path).$path.'?v='.$spec->version();
    }

    public function makesFiles(): bool
    {
        return false;
    }

    public function sizeOf(string $url): ?string
    {
        if (! str_starts_with($url, $this->baseUrl.'/')) {
            return null;
        }

        $parts = explode('/', substr($url, strlen($this->baseUrl) + 1), 3);

        if (count($parts) < 3 || ! str_starts_with($parts[2], 'plain/') || preg_match(SizeName::PATTERN, $parts[1]) !== 1) {
            return null;
        }

        return $parts[1];
    }

    public function refusal(ImageSizeSpec $spec): ?string
    {
        return (new ImgproxyPresets)->refusal($spec);
    }
}
