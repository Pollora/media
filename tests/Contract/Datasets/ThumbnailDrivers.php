<?php

declare(strict_types=1);

/*
 * The adapters of the ThumbnailDriver port, as the contract suite runs them.
 *
 * Adding a driver to the package = adding one line to the dataset: the whole contract (ThumbnailDriverContractTest.php)
 * then runs against it. Each row is [name, factory]; a factory builds the driver from the declared sizes and the config of
 * the library, so no driver is built until its test starts.
 */

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\ImgproxySigner;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;
use Pollora\Media\Infrastructure\ImgproxyThumbnailDriver;
use Pollora\Media\Infrastructure\PolloraThumbnailDriver;

dataset('thumbnail drivers', [
    'pollora' => ['pollora', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new PolloraThumbnailDriver($sizes->names(), $config, $sizes)],
    'bunny' => ['bunny', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new BunnyThumbnailDriver($config, $sizes)],
    'imgproxy' => ['imgproxy', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $config, $sizes, 'https://img.client.fr')],
    'imgproxy (s3 source)' => ['imgproxy (s3 source)', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $config, $sizes, 'https://img.client.fr', 'client-media')],
]);

/**
 * The differences between drivers that the contract found and that nobody has decided yet: `[adapter, case] => why`.
 * None today: every driver answers the same way (#58).
 *
 * See Contract.php: a listed case still runs, is reported as skipped while it fails, and FAILS the day it passes.
 */
const THUMBNAIL_KNOWN_DEVIATIONS = [];
