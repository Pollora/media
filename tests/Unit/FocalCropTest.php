<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\SizePruner;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Application\Services\ThumbnailService;
use Pollora\Media\Domain\Enums\Anchor;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\FocalPoint;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizeGeometry;
use Pollora\Media\Infrastructure\Locking\FlockGenerationLock;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\PngHeader;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

function focalCard(?FocalPoint $focal = null, Anchor $anchor = Anchor::Center): ImageSizeSpec
{
    return new ImageSizeSpec('card', Fit::Cover, 600, 400, anchor: $anchor, focal: $focal);
}

it('centres the region of a cover on the focal point', function (): void {
    $geometry = new SizeGeometry;

    // A 3000 x 1000 original, a 3:2 box: the region is 1500 x 1000, free to move along x.
    expect($geometry->coverRegion(3000, 1000, 600, 400, Anchor::Center, new FocalPoint(0.25, 0.5)))->toBe(['x' => 0, 'y' => 0, 'width' => 1500, 'height' => 1000])
        ->and($geometry->coverRegion(3000, 1000, 600, 400, Anchor::Center, new FocalPoint(0.6, 0.5)))->toBe(['x' => 1050, 'y' => 0, 'width' => 1500, 'height' => 1000])
        // A 2000 x 2000 original: the region is 2000 x 1333, free to move along y.
        ->and($geometry->coverRegion(2000, 2000, 600, 400, Anchor::Center, new FocalPoint(0.5, 0.6)))->toBe(['x' => 0, 'y' => 534, 'width' => 2000, 'height' => 1333]);
});

it('keeps the region inside the image at every corner and edge', function (float $x, float $y, int $left, int $top): void {
    $region = (new SizeGeometry)->coverRegion(3000, 2000, 400, 400, Anchor::Center, new FocalPoint($x, $y));

    expect($region)->toBe(['x' => $left, 'y' => $top, 'width' => 2000, 'height' => 2000]);
})->with([
    'top-left' => [0.0, 0.0, 0, 0],
    'top-right' => [1.0, 0.0, 1000, 0],
    'bottom-left' => [0.0, 1.0, 0, 0],
    'bottom-right' => [1.0, 1.0, 1000, 0],
    'the centre' => [0.5, 0.5, 500, 0],
    'near the right edge' => [0.9, 0.5, 1000, 0],
]);

it('lets the focal point replace the anchor, and gives the centre without one', function (): void {
    $geometry = new SizeGeometry;
    $centred = $geometry->coverRegion(3000, 1000, 600, 400);

    expect($geometry->coverRegion(3000, 1000, 600, 400, Anchor::Left, new FocalPoint(0.7, 0.5))['x'])->toBe(1350)
        ->and($geometry->coverRegion(3000, 1000, 600, 400, Anchor::Left))->toBe(['x' => 0, 'y' => 0, 'width' => 1500, 'height' => 1000])
        ->and($geometry->coverRegion(3000, 1000, 600, 400, Anchor::Center, new FocalPoint(0.5, 0.5)))->toBe($centred);
});

it('does not change the dimensions of a size: a focal point only moves the crop', function (): void {
    $geometry = new SizeGeometry;

    expect($geometry->dimensions(focalCard(new FocalPoint(0.1, 0.9)), 3000, 2000))->toBe($geometry->dimensions(focalCard(), 3000, 2000))
        ->and($geometry->dimensions(focalCard(new FocalPoint(0.1, 0.9)), 300, 300))->toBe([300, 200]);
});

it('only means something for a cover', function (): void {
    $point = new FocalPoint(0.2, 0.8);
    $scale = new ImageSizeSpec('hero', Fit::Scale, 1920, null);

    expect($scale->focusedOn($point))->toBe($scale)
        ->and($scale->focusedOn($point)->focal)->toBeNull()
        ->and(fn () => new ImageSizeSpec('hero', Fit::Contain, 600, 400, focal: $point))->toThrow(InvalidArgumentException::class)
        ->and(focalCard()->focusedOn(null)->focal)->toBeNull()
        ->and(focalCard()->focusedOn($point)->focal)->toBe($point);
});

it('keeps the fingerprint of a size with no focal point, and changes it with the point', function (): void {
    $plain = focalCard();
    $a = focalCard(new FocalPoint(0.25, 0.75));
    $b = focalCard(new FocalPoint(0.25, 0.76));

    expect($plain->fingerprint())->toBe('cover|600|400|webp|80|0')
        ->and($a->fingerprint())->not->toBe($plain->fingerprint())
        ->and($a->fingerprint())->not->toBe($b->fingerprint())
        ->and($a->version())->not->toBe($plain->version())
        ->and($a->fingerprint())->toBe(focalCard(new FocalPoint(0.1 + 0.15, 0.75))->fingerprint());
});

it('writes the focal point in the name of a file, signed, and reads it back', function (): void {
    $sizes = new ImageSizes(fn (): string => 'secret-key');
    $sizes->add('card')->cover(600, 400);
    $names = $sizes->names();
    $plain = $names->for('2026/10/photo.jpg', $sizes->get('card'), 3000, 2000);
    $focused = $names->for('2026/10/photo.jpg', $sizes->get('card')->focusedOn(new FocalPoint(0.25, 0.75)), 3000, 2000);
    $parsed = $names->parse($focused);

    expect($plain)->toMatch('#^2026/10/photo-card-600x400-[0-9a-f]{12}\.webp$#D')
        ->and($focused)->toMatch('#^2026/10/photo-card-600x400-f0250_0750-[0-9a-f]{12}\.webp$#D')
        ->and($parsed['focal']?->token())->toBe('f0250_0750')
        ->and($parsed['stem'])->toBe('photo')
        ->and($parsed['name'])->toBe('card')
        ->and($parsed['signature'])->not->toBe($names->parse($plain)['signature'])
        ->and($names->parse($plain)['focal'])->toBeNull()
        ->and($names->parse('2026/10/photo-card-600x400-f1001_0000-'.str_repeat('a', 12).'.webp'))->toBeNull();
});

it('does not rename the sizes that do not crop, whatever the point', function (): void {
    $sizes = new ImageSizes(fn (): string => 'secret-key');
    $sizes->add('hero')->scale(width: 1920);
    $names = $sizes->names();

    expect($names->for('2026/10/photo.jpg', $sizes->get('hero')->focusedOn(new FocalPoint(0.2, 0.2)), 3000, 2000))
        ->toBe($names->for('2026/10/photo.jpg', $sizes->get('hero'), 3000, 2000));
});

describe('the fallback route', function (): void {
    beforeEach(function (): void {
        $this->disk = new TemporaryStorage;
        $this->sizes = new ImageSizes(fn (): string => 'secret-key');
        $this->sizes->add('card')->cover(600, 400)->webp(75);
        $this->sizes->add('hero')->scale(width: 1200);
        $this->disk->storage->write('2026/10/photo.png', PngHeader::bytes(3000, 2000).str_repeat('x', 100));
        $this->processor = new FakeImageProcessor($this->disk->storage);
        $this->lockDir = sys_get_temp_dir().'/pollora-media-focal-locks-'.bin2hex(random_bytes(4));
        $this->service = new ThumbnailService(
            $this->disk->storage,
            $this->sizes,
            $this->processor,
            new FlockGenerationLock($this->lockDir),
            new ThumbnailLimits(maxPixels: 50_000_000, maxBytes: 30 * 1024 * 1024, concurrency: 2, wait: 0),
            static fn (string $key): bool => false,
        );
        $this->focusedName = fn (float $x, float $y, string $size = 'card'): string => $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get($size)->focusedOn(new FocalPoint($x, $y)), 3000, 2000);
    });

    afterEach(function (): void {
        foreach (glob($this->lockDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        is_dir($this->lockDir) && rmdir($this->lockDir);
    });

    it('makes a crop around the focal point its name states, at the path of the URL', function (): void {
        $file = ($this->focusedName)(0.25, 0.75);

        $result = $this->service->serve($file);

        expect($result->status)->toBe(200)
            ->and($this->disk->storage->exists($file))->toBeTrue()
            ->and($this->processor->made)->toHaveCount(1)
            ->and($this->processor->made[0]['focal'])->toBe((new FocalPoint(0.25, 0.75))->fingerprint());
    });

    it('still makes the plain size, with no point', function (): void {
        $this->service->serve($this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000));

        expect($this->processor->made[0]['focal'])->toBeNull();
    });

    it('does not make a crop around a point the signature does not cover', function (string $case): void {
        $plain = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000);
        $name = match ($case) {
            'another point than the one signed' => str_replace('f0250_0750', 'f0900_0100', ($this->focusedName)(0.25, 0.75)),
            'a point added to a plain name' => str_replace('-600x400-', '-600x400-f0250_0750-', $plain),
            default => str_replace('f0250_0750', 'f2000_0000', ($this->focusedName)(0.25, 0.75)),
        };

        $result = $this->service->serve($name);

        expect($result->status)->toBe(404)->and($this->processor->made)->toBe([]);
    })->with(['another point than the one signed', 'a point added to a plain name', 'a point out of range']);

    it('refuses a point on a size that does not crop', function (): void {
        $plain = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('hero'), 3000, 2000);
        $forged = str_replace('-1200x800-', '-1200x800-f0250_0750-', $plain);

        expect($this->service->serve($forged)->status)->toBe(404)->and($this->processor->made)->toBe([]);
    });
});

describe('the pruner', function (): void {
    beforeEach(function (): void {
        $this->disk = new TemporaryStorage;
        $this->sizes = new ImageSizes(fn (): string => 'secret-key');
        $this->sizes->add('card')->cover(600, 400);
        $this->original = '2026/10/photo.jpg';
        $this->plain = $this->sizes->names()->for($this->original, $this->sizes->get('card'), 3000, 2000);
        $this->current = $this->sizes->names()->for($this->original, $this->sizes->get('card')->focusedOn(new FocalPoint(0.25, 0.75)), 3000, 2000);
        $this->previous = $this->sizes->names()->for($this->original, $this->sizes->get('card')->focusedOn(new FocalPoint(0.5, 0.1)), 3000, 2000);

        foreach ([$this->original, $this->plain, $this->current, $this->previous] as $file) {
            $this->disk->storage->write($file, 'x');
        }
    });

    it('removes the crop around a point that was moved, and keeps the one around the current point', function (): void {
        $pruner = new SizePruner($this->disk->storage, $this->sizes, fn (string $original): ?FocalPoint => new FocalPoint(0.25, 0.75));

        expect($pruner->stale())->toBe([$this->previous]);
    });

    it('removes every crop around a point that was removed, and keeps the plain size', function (): void {
        $pruner = new SizePruner($this->disk->storage, $this->sizes, fn (string $original): ?FocalPoint => null);

        expect($pruner->stale())->toBe([$this->current, $this->previous]);
    });

    it('never takes a crop around a point for stale on its signature alone, with no way to know the current point', function (): void {
        expect((new SizePruner($this->disk->storage, $this->sizes))->stale())->toBe([]);
    });

    it('removes a crop whose signature is not the one of its point', function (): void {
        $forged = str_replace('f0250_0750', 'f0300_0750', $this->current);
        $this->disk->storage->write($forged, 'x');

        expect((new SizePruner($this->disk->storage, $this->sizes))->stale())->toBe([$forged]);
    });
});
