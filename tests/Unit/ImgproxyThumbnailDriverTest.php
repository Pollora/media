<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\ImgproxySigner;
use Pollora\Media\Infrastructure\ImgproxyThumbnailDriver;

beforeEach(function (): void {
    $this->signer = new ImgproxySigner('736563726574', '68656C6C6F');
    $this->config = new MediaConfig('https://media.client.fr');
    $this->driver = new ImgproxyThumbnailDriver($this->signer, $this->config, 'https://img.client.fr');
    $this->card = new ImageSizeSpec('card', Fit::Cover, 600, 400);
});

it('builds a signed presets-only URL: signature, preset, plain source, output extension', function (): void {
    $path = '/card/plain/https://media.client.fr/2026/10/photo.jpg@webp';

    expect($this->driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))
        ->toBe('https://img.client.fr/'.$this->signer->sign($path).$path);
});

it('writes the extension of the format of the size, and none for the original format', function (): void {
    $avif = new ImageSizeSpec('hero', Fit::Scale, 1920, null, OutputFormat::Avif);
    $original = new ImageSizeSpec('raw', Fit::Scale, 800, null, OutputFormat::Original);

    expect($this->driver->url('a.jpg', $avif, 3000, 2000))->toEndWith('/hero/plain/https://media.client.fr/a.jpg@avif')
        ->and($this->driver->url('a.jpg', $original, 3000, 2000))->toEndWith('/raw/plain/https://media.client.fr/a.jpg');
});

it('escapes what imgproxy would read as syntax in a plain source: percent, question mark and at sign', function (): void {
    $url = $this->driver->url('2026/10/photo@2x 100%.jpg', $this->card, 3000, 2000);
    $path = '/card/plain/https://media.client.fr/2026/10/photo%402x 100%25.jpg@webp';

    expect($url)->toBe('https://img.client.fr/'.$this->signer->sign($path).$path)
        ->and($this->driver->url('a?b.jpg', $this->card, 3000, 2000))->toContain('a%3Fb.jpg@webp');
});

it('points at the object of the bucket when the source is s3', function (): void {
    $driver = new ImgproxyThumbnailDriver($this->signer, $this->config, 'https://img.client.fr', 'client-media');
    $path = '/card/plain/s3://client-media/2026/10/photo.jpg@webp';

    expect($driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))->toBe('https://img.client.fr/'.$this->signer->sign($path).$path);
});

it('does not make files', function (): void {
    expect($this->driver->makesFiles())->toBeFalse();
});

it('finds the size a URL it made stands for, and nothing else', function (): void {
    $url = $this->driver->url('2026/10/photo.jpg', new ImageSizeSpec('hero-640w', Fit::Scale, 640, null), 3000, 2000);

    expect($this->driver->sizeOf($url))->toBe('hero-640w')
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg'))->toBeNull()
        ->and($this->driver->sizeOf('https://other.test/sig/card/plain/x.jpg@webp'))->toBeNull()
        ->and($this->driver->sizeOf('https://img.client.fr/sig/rs:fill:1:1/plain/x.jpg'))->toBeNull();
});

it('refuses a size named default, which imgproxy applies to every image', function (): void {
    expect($this->driver->refusal(new ImageSizeSpec('default', Fit::Scale, 100, null)))->toContain('default')
        ->and($this->driver->refusal($this->card))->toBeNull();
});
