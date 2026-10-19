<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Application\Services\ThumbnailService;
use Pollora\Media\Infrastructure\Locking\FlockGenerationLock;
use Pollora\Media\Tests\Fixtures\CountingStorage;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\PngHeader;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->counting = new CountingStorage($this->disk->storage);
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75);
    $this->sizes->add('hero')->scale(width: 1200)->widths([400]);
    $this->disk->storage->write('2026/10/photo.png', PngHeader::bytes(3000, 2000).str_repeat('x', 100));
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $this->lockDir = sys_get_temp_dir().'/pollora-media-test-locks-'.bin2hex(random_bytes(4));
    $this->limits = new ThumbnailLimits(maxPixels: 50_000_000, maxBytes: 30 * 1024 * 1024, concurrency: 2, wait: 0);
    $this->make = fn (?Closure $isPrivate = null, ?ThumbnailLimits $limits = null): ThumbnailService => new ThumbnailService(
        $this->counting,
        $this->sizes,
        $this->processor,
        new FlockGenerationLock($this->lockDir),
        $limits ?? $this->limits,
        $isPrivate ?? static fn (string $key): bool => false,
    );
    $this->file = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000);
});

afterEach(function (): void {
    foreach (glob($this->lockDir.'/*') ?: [] as $file) {
        unlink($file);
    }
    is_dir($this->lockDir) && rmdir($this->lockDir);
});

it('makes the size at the exact path of the URL, and serves it with a cache that never expires', function (): void {
    $result = ($this->make)()->serve($this->file);

    expect($result->status)->toBe(200)
        ->and($result->contentType)->toBe('image/webp')
        ->and($result->headers['Cache-Control'])->toBe('public, max-age=31536000, immutable')
        ->and(stream_get_contents($result->body))->toBe('image 600x400')
        ->and($this->disk->storage->exists($this->file))->toBeTrue()
        ->and(array_column($this->processor->made, 'size'))->toBe(['card']);
});

it('serves the file that is already there without making it again', function (): void {
    ($this->make)()->serve($this->file);
    $this->processor->made = [];

    $second = ($this->make)()->serve($this->file);

    expect($second->status)->toBe(200)
        ->and($this->processor->made)->toBe([]);
});

it('makes a bounded size of an extra width', function (): void {
    $variant = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->variantsOf($this->sizes->get('hero'))['hero-400w'], 3000, 2000);

    $result = ($this->make)()->serve($variant);

    expect($result->status)->toBe(200)->and(stream_get_contents($result->body))->toBe('image 400x267');
});

it('answers 404 without asking the disk to anything that is not a name of a declared size, signed', function (string $path): void {
    $result = ($this->make)()->serve($path);

    expect($result->status)->toBe(404)
        ->and($this->counting->existsCalls)->toBe(0)
        ->and($this->counting->readCalls)->toBe(0)
        ->and($this->processor->made)->toBe([]);
})->with([
    'not a sized name' => ['2026/10/photo-300x200.jpg'],
    'a size nobody declared' => ['2026/10/photo-banner-600x400-0123456789ab.webp'],
    'a wrong signature' => ['2026/10/photo-card-600x400-0123456789ab.webp'],
    'a path that climbs out' => ['2026/../../etc/photo-card-600x400-0123456789ab.webp'],
    'an extension that is not an image' => ['2026/10/photo-card-600x400-0123456789ab.php'],
    'an svg' => ['2026/10/logo-card-600x400-0123456789ab.svg'],
    'an empty path' => [''],
]);

it('answers 404 when the original is not there, after one question to the disk', function (): void {
    $missing = $this->sizes->names()->for('2026/10/gone.png', $this->sizes->get('card'), 3000, 2000);

    $result = ($this->make)()->serve($missing);

    expect($result->status)->toBe(404)->and($this->counting->existsCalls)->toBeLessThanOrEqual(2)->and($this->processor->made)->toBe([]);
});

it('answers 404 when the dimensions in the name are not those the original gives, so no one can name a size of their own', function (): void {
    $forged = str_replace('600x400', '1x1', $this->file);

    expect(($this->make)()->serve($forged)->status)->toBe(404)->and($this->processor->made)->toBe([]);
});

it('refuses an original that is too big or too wide, and makes nothing of it', function (): void {
    $heavy = new ThumbnailLimits(maxPixels: 1_000_000, maxBytes: 30 * 1024 * 1024, concurrency: 2, wait: 0);
    $light = new ThumbnailLimits(maxPixels: 50_000_000, maxBytes: 50, concurrency: 2, wait: 0);

    expect(($this->make)(null, $heavy)->serve($this->file)->status)->toBe(404)
        ->and(($this->make)(null, $light)->serve($this->file)->status)->toBe(404)
        ->and($this->processor->made)->toBe([]);
});

it('makes no public size of a private media', function (): void {
    $result = ($this->make)(static fn (string $key): bool => $key === '2026/10/photo.png')->serve($this->file);

    expect($result->status)->toBe(404)->and($this->processor->made)->toBe([]);
});

it('makes a GIF into nothing: its sizes are the original', function (): void {
    $this->disk->storage->write('2026/10/loop.gif', PngHeader::bytes(400, 300));
    $gifSized = $this->sizes->names()->for('2026/10/loop.gif', $this->sizes->get('card'), 400, 300);

    expect(($this->make)()->serve($gifSized)->status)->toBe(404);
});

it('hands over the nearest size already made, without keeping it in a cache, when the server is saturated', function (): void {
    $this->disk->storage->write('2026/10/photo-300x200.jpg', 'medium');
    $this->disk->storage->write('2026/10/photo-1024x683.jpg', 'large');
    $lock = new FlockGenerationLock($this->lockDir);
    $busy = [$lock->slot(2, 0), $lock->slot(2, 0)];

    $result = ($this->make)()->serve($this->file);

    expect($busy[0])->not->toBeNull()->and($busy[1])->not->toBeNull()
        ->and($result->status)->toBe(200)
        ->and($result->headers['Cache-Control'])->toBe('no-store')
        ->and(stream_get_contents($result->body))->toBe('large')
        ->and($this->disk->storage->exists($this->file))->toBeFalse();

    foreach ($busy as $slot) {
        $slot?->release();
    }
});

it('serves the original when nothing smaller exists and the server is saturated', function (): void {
    $lock = new FlockGenerationLock($this->lockDir);
    $busy = [$lock->slot(2, 0), $lock->slot(2, 0)];

    $result = ($this->make)()->serve($this->file);

    expect($result->status)->toBe(200)->and($result->headers['Cache-Control'])->toBe('no-store')
        ->and(strlen(stream_get_contents($result->body)))->toBeGreaterThan(100);

    foreach ($busy as $slot) {
        $slot?->release();
    }
});

it('serves the file another process made while it waited for the lock', function (): void {
    $lock = new FlockGenerationLock($this->lockDir);
    $held = $lock->forFile($this->file, 0);
    $this->disk->storage->write($this->file, 'made elsewhere');
    $held?->release();

    $result = ($this->make)()->serve($this->file);

    expect($result->status)->toBe(200)->and(stream_get_contents($result->body))->toBe('made elsewhere')->and($this->processor->made)->toBe([]);
});
