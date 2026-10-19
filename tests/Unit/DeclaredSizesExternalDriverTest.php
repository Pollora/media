<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\ImgproxySigner;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;
use Pollora\Media\Infrastructure\ImgproxyThumbnailDriver;
use Pollora\Media\Infrastructure\WordPress\DeclaredSizes;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75)->eager();
    $this->sizes->add('hero')->scale(width: 1920)->widths([640, 1024])->eager();
    $this->sizes->add('default')->scale(width: 100);
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $this->config = new MediaConfig('https://media.client.fr');
    $this->meta = ['file' => '2026/10/photo.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []];
    $this->private = [];
    $this->declaredWith = fn ($driver): DeclaredSizes => new DeclaredSizes(
        $this->sizes,
        $this->config,
        $this->disk->storage,
        $this->processor,
        $driver,
        fn (int $id): bool => in_array($id, $this->private, true),
    );
    Functions\when('wp_get_attachment_metadata')->justReturn($this->meta);
});

dataset('external drivers', [
    'bunny' => [fn (ImageSizes $sizes) => new BunnyThumbnailDriver(new MediaConfig('https://media.client.fr'), $sizes)],
    'imgproxy' => [fn (ImageSizes $sizes) => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), new MediaConfig('https://media.client.fr'), 'https://img.client.fr')],
]);

it('makes no file at upload when the service makes the sizes', function (callable $driver): void {
    $meta = ($this->declaredWith)($driver($this->sizes))->generate($this->meta, 7, 'create');

    expect($this->processor->made)->toBe([])->and($meta['sizes'])->toBe([]);
})->with('external drivers');

it('gives the URL of the service for a size, with the dimensions worked out from the metadata', function (callable $driver): void {
    $image = ($this->declaredWith)($driver($this->sizes))->downsize(false, 7, 'card');

    expect($image[0])->not->toBe('')->and(array_slice($image, 1))->toBe([600, 400, true]);
})->with('external drivers');

it('builds the srcset of the extra widths from the URLs of the service', function (callable $driver): void {
    $declared = ($this->declaredWith)($driver($this->sizes));
    $hero = $declared->downsize(false, 7, 'hero');

    $sources = $declared->srcset([300 => ['url' => 'wp.jpg', 'descriptor' => 'w', 'value' => 300]], [1920, 1280], $hero[0], $this->meta, 7);

    expect(array_keys($sources))->toBe([640, 1024, 1920])
        ->and($sources[640]['url'])->not->toBe($hero[0])
        ->and($sources[640]['url'])->toMatch('/hero-?640w/')
        ->and($sources[1024]['url'])->toMatch('/hero-?1024w/')
        ->and($sources[1920]['url'])->toBe($hero[0]);
})->with('external drivers');

it('leaves the srcset of WordPress alone for a URL that is not the service\'s', function (callable $driver): void {
    $sources = [300 => ['url' => 'wp.jpg', 'descriptor' => 'w', 'value' => 300]];

    expect(($this->declaredWith)($driver($this->sizes))->srcset($sources, [300, 200], 'https://media.client.fr/2026/10/photo-300x200.jpg', $this->meta, 7))->toBe($sources);
})->with('external drivers');

it('never sends a private media to the service: its size is the original, which is signed', function (callable $driver): void {
    $this->private = [7];
    $declared = ($this->declaredWith)($driver($this->sizes));
    $sources = [300 => ['url' => 'https://media.client.fr/2026/10/photo.jpg', 'descriptor' => 'w', 'value' => 300]];
    $hero = ($this->declaredWith)($driver($this->sizes))->downsize(false, 8, 'hero');

    expect($declared->downsize(false, 7, 'card'))->toBeFalse()
        ->and($declared->srcset($sources, [1920, 1280], $hero[0], $this->meta, 7))->toBe($sources);
})->with('external drivers');

it('leaves a size the service cannot serve to WordPress', function (): void {
    $driver = new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $this->config, 'https://img.client.fr');

    expect(($this->declaredWith)($driver)->downsize(false, 7, 'default'))->toBeFalse();
});

it('keeps making files and answering with them for the default driver, private or not', function (): void {
    $this->private = [7];
    $declared = ($this->declaredWith)(null);

    $declared->generate($this->meta, 7, 'create');

    expect($this->processor->made)->not->toBe([])
        ->and($declared->downsize(false, 7, 'card')[0])->toStartWith('https://media.client.fr/2026/10/photo-card-600x400-');
});
