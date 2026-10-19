<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Media\Infrastructure\WordPress\ConstantsConfiguration;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

/**
 * @param  array<string, mixed>  $constants
 */
function constants(array $constants): ConstantsConfiguration
{
    return new ConstantsConfiguration(static fn (string $name): mixed => $constants[$name] ?? null);
}

it('falls back to the folder and the URL WordPress uses, so nothing changes until a setting is made', function (): void {
    $configuration = constants(['WP_CONTENT_DIR' => '/var/www/public/content', 'WP_CONTENT_URL' => 'https://site.test/content']);

    expect($configuration->config()->url)->toBe('https://site.test/content/uploads')
        ->and($configuration->config()->legacyUrls)->toBe([])
        ->and($configuration->localRoot())->toBe('/var/www/public/content/uploads');
});

it('reads the POLLORA_MEDIA constants into the configuration', function (): void {
    $config = constants([
        'POLLORA_MEDIA_URL' => 'https://media.client.fr/',
        'POLLORA_MEDIA_LEGACY_URLS' => ['https://old.test/content/uploads', 'https://older.test/uploads/'],
        'POLLORA_MEDIA_CACHE_CONTROL' => 'public, max-age=60',
        'POLLORA_MEDIA_PRIVATE_EXPIRY' => '+1 hour',
        'POLLORA_MEDIA_ASYNC_THUMBNAILS' => true,
    ])->config();

    expect($config->url)->toBe('https://media.client.fr')
        ->and($config->legacyUrls)->toBe(['https://old.test/content/uploads', 'https://older.test/uploads'])
        ->and($config->cacheControl)->toBe('public, max-age=60')
        ->and($config->privateExpiry)->toBe('+1 hour')
        ->and($config->asyncThumbnails)->toBeTrue();
});

it('accepts the legacy URLs as a comma separated string', function (): void {
    $config = constants(['POLLORA_MEDIA_URL' => 'https://m.test', 'POLLORA_MEDIA_LEGACY_URLS' => 'https://a.test/u, https://b.test/u'])->config();

    expect($config->legacyUrls)->toBe(['https://a.test/u', 'https://b.test/u']);
});

it('builds a local filesystem from POLLORA_MEDIA_ROOT', function (): void {
    $disk = new TemporaryStorage;
    $filesystem = constants(['POLLORA_MEDIA_DRIVER' => 'local', 'POLLORA_MEDIA_ROOT' => $disk->root])->filesystem();
    $filesystem->write('a/b.txt', 'x');

    expect(file_get_contents($disk->root.'/a/b.txt'))->toBe('x');
});

it('hands the filesystem to a filter first, for a service the constants cannot describe', function (): void {
    $custom = new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir()));
    Filters\expectApplied('pollora/media/filesystem')->once()->with(null)->andReturn($custom);

    expect(constants(['POLLORA_MEDIA_DRIVER' => 'sftp'])->filesystem())->toBe($custom);
});

it('says what to install when the S3 driver is asked for and its SDK is not there', function (): void {
    $configuration = constants(['POLLORA_MEDIA_DRIVER' => 's3', 'POLLORA_MEDIA_BUCKET' => 'media', 'POLLORA_MEDIA_REGION' => 'eu-west-3']);

    expect(fn () => $configuration->filesystem())->toThrow(RuntimeException::class, 'league/flysystem-aws-s3-v3');
})->skip(fn () => class_exists('League\Flysystem\AwsS3V3\AwsS3V3Adapter'), 'the S3 SDK is installed');

it('refuses a driver it does not know, and an S3 disk without a bucket', function (): void {
    expect(fn () => constants(['POLLORA_MEDIA_DRIVER' => 'ftp'])->filesystem())->toThrow(RuntimeException::class, 'pollora/media/filesystem')
        ->and(fn () => constants(['POLLORA_MEDIA_DRIVER' => 's3'])->filesystem())->toThrow(RuntimeException::class, 'POLLORA_MEDIA_BUCKET');
});

it('reads the limits of a size made on demand, with the defaults of the specification', function (): void {
    $default = constants([])->thumbnailLimits();
    $set = constants(['POLLORA_MEDIA_THUMBNAILS_MAX_PIXELS' => 10_000_000, 'POLLORA_MEDIA_THUMBNAILS_CONCURRENCY' => 2, 'POLLORA_MEDIA_THUMBNAILS_WAIT' => 3])->thumbnailLimits();

    expect([$default->maxPixels, $default->maxBytes, $default->concurrency, $default->wait])->toBe([50_000_000, 31_457_280, 4, 10])
        ->and([$set->maxPixels, $set->concurrency, $set->wait])->toBe([10_000_000, 2, 3]);
});
