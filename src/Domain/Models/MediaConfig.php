<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Models;

/**
 * What the package needs to know, and nothing it would read for itself: the
 * public URL of the media and the few settings around it.
 *
 * Built by the Laravel connector from `config/media.php`, and from the
 * `POLLORA_MEDIA_*` constants without Laravel.
 */
final readonly class MediaConfig
{
    /** The public URL of the media library, without a trailing slash: a media URL is this and a relative path. */
    public string $url;

    /** @var list<string> Old base URLs of the library, replaced by `url` while the content still holds them */
    public array $legacyUrls;

    /**
     * @param  list<string>  $legacyUrls
     */
    public function __construct(
        string $url,
        array $legacyUrls = [],
        public string $cacheControl = 'public, max-age=31536000, immutable',
        public string $privateExpiry = '+6 hours',
        public bool $asyncThumbnails = false,
    ) {
        $this->url = rtrim($url, '/');
        $this->legacyUrls = array_map(static fn (string $legacy): string => rtrim($legacy, '/'), $legacyUrls);
    }
}
