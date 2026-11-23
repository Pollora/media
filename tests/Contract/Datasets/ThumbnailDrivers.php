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
    'pollora' => ['pollora', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new PolloraThumbnailDriver($sizes->names(), $config)],
    'bunny' => ['bunny', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new BunnyThumbnailDriver($config, $sizes)],
    'imgproxy' => ['imgproxy', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $config, 'https://img.client.fr')],
    'imgproxy (s3 source)' => ['imgproxy (s3 source)', static fn (ImageSizes $sizes, MediaConfig $config): ThumbnailDriver => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $config, 'https://img.client.fr', 'client-media')],
]);

/**
 * The differences between drivers that the contract found and that nobody has decided yet: `[adapter, case] => why`.
 *
 * See Contract.php: a listed case still runs, is reported as skipped while it fails, and FAILS the day it passes.
 */
const THUMBNAIL_KNOWN_DEVIATIONS = [
    'pollora|leading slash' => 'PolloraThumbnailDriver signs the key as it is, so "/a.jpg" and "a.jpg" give two file names; the other drivers trim the slash (SizedFileName.php:57, which signs the raw key).',
    'pollora|unknown dimensions of a scale or a contain' => 'PolloraThumbnailDriver::url() throws DivisionByZeroError for a scale or a contain when the original is 0 pixels wide or tall; the other drivers never read the dimensions (SizeGeometry.php:110-112 and :123 divide by the width and height of the original).',
    'bunny|key with ? or #' => 'BunnyThumbnailDriver does not encode the key: "what?.jpg?class=card" has its class read as part of the path, so sizeOf() answers null (imgproxy escapes % ? @; BunnyThumbnailDriver.php:35).',
    'pollora|undeclared size is not recognised' => 'PolloraThumbnailDriver::sizeOf() (PolloraThumbnailDriver.php:33-43) answers the name found in the file name, declared or not (the port says "the name of the declared size"; BunnyThumbnailDriver.php:56 checks the declared sizes); no effect today, DeclaredSizes::srcset() looks the name up in the declared sizes afterwards.',
    'imgproxy|undeclared size is not recognised' => 'Same as pollora (ImgproxyThumbnailDriver::sizeOf() (ImgproxyThumbnailDriver.php:53-66) answers any well-formed preset name).',
    'imgproxy (s3 source)|undeclared size is not recognised' => 'Same as pollora.',
    'imgproxy|refused size is not recognised' => 'ImgproxyThumbnailDriver::sizeOf() (ImgproxyThumbnailDriver.php:53-66) ignores refusal(): the "default" preset is refused but its URL is still recognised (BunnyThumbnailDriver.php:56 checks refusal()).',
    'imgproxy (s3 source)|refused size is not recognised' => 'Same as imgproxy.',
];
