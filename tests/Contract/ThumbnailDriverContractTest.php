<?php

declare(strict_types=1);

/*
 * The contract of the ThumbnailDriver port: what every driver does the same way, whatever it builds the URL of a size
 * from. Run against each adapter of Datasets/ThumbnailDrivers.php.
 *
 * What a driver may do differently is not asserted here: the shape of the URL, makesFiles(), and which sizes it refuses.
 */

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Contracts\ThumbnailDriver;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;

beforeEach(function (): void {
    $this->config = new MediaConfig('https://media.client.fr');
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400);
    $this->sizes->add('hero')->scale(width: 1920)->widths([640, 1024]);
    $this->sizes->add('my_size')->scale(width: 100);
    $this->sizes->add('banner')->contain(800, 200);
    $this->sizes->add('tall')->scale(height: 300);
    $this->card = $this->sizes->get('card');

    $this->driverFrom = fn (Closure $make): ThumbnailDriver => $make($this->sizes, $this->config);
});

it('gives an absolute http(s) URL for a size of an original', function (string $adapter, Closure $make): void {
    $url = ($this->driverFrom)($make)->url('2026/10/photo.jpg', $this->card, 3000, 2000);

    expect(parse_url($url, PHP_URL_SCHEME))->toBeIn(['http', 'https'])
        ->and(parse_url($url, PHP_URL_HOST))->toBeString()->not->toBeEmpty();
})->with('thumbnail drivers');

it('gives the same URL every time for the same arguments', function (string $adapter, Closure $make): void {
    $driver = ($this->driverFrom)($make);

    expect($driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))->toBe($driver->url('2026/10/photo.jpg', $this->card, 3000, 2000));
})->with('thumbnail drivers');

it('does not care about a leading slash on the key of the original', function (string $adapter, Closure $make): void {
    contractCase(THUMBNAIL_KNOWN_DEVIATIONS, $adapter, 'leading slash', function () use ($make): void {
        $driver = ($this->driverFrom)($make);

        expect($driver->url('/2026/10/photo.jpg', $this->card, 3000, 2000))->toBe($driver->url('2026/10/photo.jpg', $this->card, 3000, 2000));
    });
})->with('thumbnail drivers');

it('finds again the name of every declared size, extra widths included, from the URL it gave', function (string $adapter, Closure $make, string $name): void {
    $driver = ($this->driverFrom)($make);
    $spec = $this->sizes->withVariants()[$name];

    expect($driver->sizeOf($driver->url('2026/10/photo.jpg', $spec, 3000, 2000)))->toBe($name);
})->with('thumbnail drivers')->with(['plain' => 'card', 'extra width' => 'hero-640w', 'underscore' => 'my_size', 'contain' => 'banner']);

it('finds the size again whatever the original, its folder or its dimensions', function (string $adapter, Closure $make, string $key, int $width, int $height): void {
    $driver = ($this->driverFrom)($make);

    expect($driver->sizeOf($driver->url($key, $this->card, $width, $height)))->toBe('card');
})->with('thumbnail drivers')->with([
    'at the root' => ['photo.jpg', 3000, 2000],
    'deep folder' => ['2026/10/a/b/c/photo.jpg', 3000, 2000],
    'smaller than the size' => ['2026/10/photo.jpg', 100, 80],
    'one pixel' => ['2026/10/photo.jpg', 1, 1],
    'portrait' => ['2026/10/photo.jpg', 2000, 3000],
    'no extension' => ['2026/10/photo', 3000, 2000],
    'upper case extension' => ['2026/10/PHOTO.JPG', 3000, 2000],
]);

it('does not fail on an original whose dimensions WordPress did not record, whatever the size', function (string $adapter, Closure $make, string $name): void {
    contractCase(THUMBNAIL_KNOWN_DEVIATIONS, $adapter, $name === 'card' ? 'unknown dimensions of a cover' : 'unknown dimensions of a scale or a contain', function () use ($make, $name): void {
        $driver = ($this->driverFrom)($make);

        expect($driver->sizeOf($driver->url('2026/10/photo.jpg', $this->sizes->get($name), 0, 0)))->toBe($name);
    });
})->with('thumbnail drivers')->with(['cover' => 'card', 'scale' => 'tall', 'contain' => 'banner']);

it('finds the size again when the key holds characters a URL reads as syntax', function (string $adapter, Closure $make, string $key, string $case): void {
    contractCase(THUMBNAIL_KNOWN_DEVIATIONS, $adapter, $case, function () use ($make, $key): void {
        $driver = ($this->driverFrom)($make);

        expect($driver->sizeOf($driver->url($key, $this->card, 3000, 2000)))->toBe('card');
    });
})->with('thumbnail drivers')->with([
    'space' => ['2026/10/my photo.jpg', 'plain key'],
    'percent' => ['2026/10/100%.jpg', 'plain key'],
    'question mark' => ['2026/10/what?.jpg', 'key with ? or #'],
    'at sign' => ['2026/10/photo@2x.jpg', 'plain key'],
    'hash' => ['2026/10/photo#1.jpg', 'key with ? or #'],
    'accent' => ['2026/10/été.jpg', 'plain key'],
]);

it('knows nothing of a URL it did not make', function (string $adapter, Closure $make, string $url): void {
    expect(($this->driverFrom)($make)->sizeOf($url))->toBeNull();
})->with('thumbnail drivers')->with([
    'empty' => [''],
    'another host' => ['https://other.test/2026/10/photo.jpg?class=card'],
    'not a URL' => ['card'],
    'the original of the library' => ['https://media.client.fr/2026/10/photo.jpg'],
    'the library root' => ['https://media.client.fr/'],
]);

it('gives another URL for another size, another original, or a size that changed', function (string $adapter, Closure $make): void {
    $driver = ($this->driverFrom)($make);
    $url = $driver->url('2026/10/photo.jpg', $this->card, 3000, 2000);
    $sharper = new ImageSizeSpec('card', Fit::Cover, 600, 400, quality: 95);
    $avif = new ImageSizeSpec('card', Fit::Cover, 600, 400, OutputFormat::Avif);

    expect($driver->url('2026/10/photo.jpg', $this->sizes->get('banner'), 3000, 2000))->not->toBe($url)
        ->and($driver->url('2026/10/other.jpg', $this->card, 3000, 2000))->not->toBe($url)
        ->and($driver->url('2026/10/photo.jpg', $sharper, 3000, 2000))->not->toBe($url)
        ->and($driver->url('2026/10/photo.jpg', $avif, 3000, 2000))->not->toBe($url);
})->with('thumbnail drivers');

it('serves from the library when it makes files, and has no refusal for an ordinary size', function (string $adapter, Closure $make): void {
    $driver = ($this->driverFrom)($make);

    if ($driver->makesFiles()) {
        expect($driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))->toStartWith($this->config->url.'/');
    }

    foreach ($this->sizes->withVariants() as $spec) {
        expect($driver->refusal($spec))->toBeNull();
    }
})->with('thumbnail drivers');

it('gives a reason, never an empty string, when it refuses a size', function (string $adapter, Closure $make, ImageSizeSpec $spec): void {
    $refusal = ($this->driverFrom)($make)->refusal($spec);

    expect($refusal === null || trim($refusal) !== '')->toBeTrue();
})->with('thumbnail drivers')->with([
    'a name no service accepts' => [new ImageSizeSpec('___', Fit::Scale, 100, null)],
    'the name imgproxy applies to everything' => [new ImageSizeSpec('default', Fit::Scale, 100, null)],
]);

it('does not recognise the URL of a size it refuses', function (string $adapter, Closure $make, ImageSizeSpec $spec): void {
    $driver = ($this->driverFrom)($make);

    if ($driver->refusal($spec) === null) {
        // Nothing is refused: the size is served, so the driver gives its URL (the case below is for a driver that refuses).
        expect($driver->url('2026/10/photo.jpg', $spec, 3000, 2000))->toBeString()->not->toBeEmpty();

        return;
    }

    contractCase(THUMBNAIL_KNOWN_DEVIATIONS, $adapter, 'refused size is not recognised', function () use ($driver, $spec): void {
        expect($driver->sizeOf($driver->url('2026/10/photo.jpg', $spec, 3000, 2000)))->toBeNull();
    });
})->with('thumbnail drivers')->with([
    'a name no service accepts' => [new ImageSizeSpec('___', Fit::Scale, 100, null)],
    'the name imgproxy applies to everything' => [new ImageSizeSpec('default', Fit::Scale, 100, null)],
]);

it('claims no size nobody declared', function (string $adapter, Closure $make): void {
    contractCase(THUMBNAIL_KNOWN_DEVIATIONS, $adapter, 'undeclared size is not recognised', function () use ($make): void {
        $driver = ($this->driverFrom)($make);
        $ghost = new ImageSizeSpec('ghost', Fit::Scale, 100, null);

        expect($driver->sizeOf($driver->url('2026/10/photo.jpg', $ghost, 3000, 2000)))->toBeNull();
    });
})->with('thumbnail drivers');
