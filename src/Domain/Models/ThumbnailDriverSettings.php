<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Models;

use SensitiveParameter;

/**
 * Who makes the image sizes, and what each service needs to be told: `pollora` (the default, files of
 * the library made by the module), `bunny` or `imgproxy` (a URL a service resolves).
 *
 * Built by the Laravel connector from `media.thumbnails`, and from the `POLLORA_MEDIA_*` constants
 * without Laravel. The keys are only ever used to sign a URL or to call an API: they never reach a log.
 */
final readonly class ThumbnailDriverSettings
{
    public const string POLLORA = 'pollora';

    public const string BUNNY = 'bunny';

    public const string IMGPROXY = 'imgproxy';

    /** Base URL of imgproxy, without a trailing slash */
    public ?string $imgproxyUrl;

    /**
     * @param  string  $bunnyPullZone  Id of the pull zone: only used to export the classes
     * @param  string  $bunnyApiKey  Access key of the Bunny account: only used to export the classes
     * @param  string  $imgproxyKey  Hexadecimal, as IMGPROXY_KEY
     * @param  string  $imgproxySalt  Hexadecimal, as IMGPROXY_SALT
     * @param  int  $imgproxySignatureSize  As IMGPROXY_SIGNATURE_SIZE: it must match the server
     * @param  string  $imgproxySource  `url` (the URL of the library) or `s3` (the object of the bucket)
     */
    public function __construct(
        public string $driver = self::POLLORA,
        public ?string $bunnyPullZone = null,
        #[SensitiveParameter] public ?string $bunnyApiKey = null,
        ?string $imgproxyUrl = null,
        #[SensitiveParameter] public ?string $imgproxyKey = null,
        #[SensitiveParameter] public ?string $imgproxySalt = null,
        public int $imgproxySignatureSize = 32,
        public string $imgproxySource = 'url',
        public ?string $imgproxyBucket = null,
    ) {
        $this->imgproxyUrl = $imgproxyUrl === null ? null : rtrim($imgproxyUrl, '/');
    }
}
