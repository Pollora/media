<?php

declare(strict_types=1);

use Pollora\Media\Domain\Exceptions\BunnyRequestFailedException;
use Pollora\Media\Domain\Exceptions\ImageProcessingFailedException;
use Pollora\Media\Domain\Exceptions\InvalidMediaConfigurationException;

it('names the constant that holds an unknown driver and the ways out', function (): void {
    $exception = InvalidMediaConfigurationException::unknownDriver('gcs');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toStartWith('POLLORA_MEDIA_DRIVER "gcs" is not known')
        ->and($exception->getMessage())->toContain('"local" or "s3"', 'pollora/media/filesystem');
});

it('says where the missing bucket is defined and which package the S3 driver needs', function (): void {
    expect(InvalidMediaConfigurationException::missingBucket()->getMessage())->toStartWith('POLLORA_MEDIA_BUCKET is required with POLLORA_MEDIA_DRIVER "s3".')
        ->and(InvalidMediaConfigurationException::missingS3Package()->getMessage())->toContain('composer require league/flysystem-aws-s3-v3');
});

it('explains which format a server cannot write and what to install', function (): void {
    $exception = ImageProcessingFailedException::unsupportedFormat('image/avif', 'thumbnail');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toStartWith('The image editor of this server cannot write image/avif: size "thumbnail" is not made.')
        ->and(ImageProcessingFailedException::unsupportedFormat('', 'thumbnail')->getMessage())->toContain('cannot write this format')
        ->and(ImageProcessingFailedException::because('crop failed')->getMessage())->toBe('crop failed');
});

it('reports what Bunny answered, and what to check', function (): void {
    expect(BunnyRequestFailedException::unreachable('timeout')->getMessage())->toBe('Bunny could not be reached: timeout')
        ->and(BunnyRequestFailedException::refused(401, 'POST', 42)->getMessage())->toStartWith('Bunny answered 401 to POST on the pull zone 42.')
        ->and(BunnyRequestFailedException::refused(401, 'POST', 42))->toBeInstanceOf(RuntimeException::class);
});
