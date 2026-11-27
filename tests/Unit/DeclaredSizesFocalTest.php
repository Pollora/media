<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Models\FocalPoint;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\ImgproxySigner;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;
use Pollora\Media\Infrastructure\ImgproxyThumbnailDriver;
use Pollora\Media\Infrastructure\WordPress\DeclaredSizes;
use Pollora\Media\Infrastructure\WordPress\FocalPoints;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->config = new MediaConfig('https://media.client.fr');
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75)->eager();
    $this->sizes->add('hero')->scale(width: 1920)->widths([640])->eager();
    $this->sizes->add('banner')->cover(1200, 300)->widths([600]);
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $this->meta = ['file' => '2026/10/photo.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []];
    $this->point = new FocalPoint(0.25, 0.75);
    $this->postMeta = [7 => ['x' => 0.25, 'y' => 0.75]];
    Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->postMeta[$id] ?? '');
    Functions\when('wp_get_attachment_metadata')->justReturn($this->meta);
    $this->make = fn ($driver = null, ?Closure $isPrivate = null): DeclaredSizes => new DeclaredSizes($this->sizes, $this->config, $this->disk->storage, $this->processor, $driver, $isPrivate, new FocalPoints);
    $this->plain = fn (string $size): string => $this->sizes->names()->for('2026/10/photo.jpg', $this->sizes->get($size), 3000, 2000);
    $this->around = fn (string $size): string => $this->sizes->names()->for('2026/10/photo.jpg', $this->sizes->get($size)->focusedOn($this->point), 3000, 2000);
    $this->imgproxy = fn () => new ImgproxyThumbnailDriver(new ImgproxySigner('736563726574', '68656C6C6F'), $this->config, $this->sizes, 'https://img.client.fr');
});

it('gives the URL of a cover around the focal point of the image, with the dimensions it always had', function (): void {
    $declared = ($this->make)();

    expect($declared->downsize(false, 7, 'card'))->toBe(['https://media.client.fr/'.($this->around)('card'), 600, 400, true])
        ->and(($this->around)('card'))->toContain('-f0250_0750-')
        ->and($declared->downsize(false, 8, 'card'))->toBe(['https://media.client.fr/'.($this->plain)('card'), 600, 400, true]);
});

it('does not move the URL of a size that does not crop', function (): void {
    expect(($this->make)()->downsize(false, 7, 'hero')[0])->toBe('https://media.client.fr/'.($this->plain)('hero'));
});

it('serves a cover around a focal point from the module whatever the driver, and the rest from the service', function (string $service): void {
    $declared = ($this->make)($service === 'bunny' ? new BunnyThumbnailDriver($this->config, $this->sizes) : ($this->imgproxy)());
    $service = $declared->downsize(false, 8, 'card')[0];

    expect($declared->downsize(false, 7, 'card')[0])->toBe('https://media.client.fr/'.($this->around)('card'))
        ->and($service)->not->toContain('card-600x400')
        ->and($declared->downsize(false, 7, 'hero')[0])->toBe($declared->downsize(false, 8, 'hero')[0]);
})->with(['imgproxy', 'bunny']);

it('keeps a private media from the service, with or without a focal point', function (): void {
    $declared = ($this->make)(($this->imgproxy)(), static fn (int $id): bool => true);

    expect($declared->downsize(false, 7, 'card'))->toBeFalse()
        ->and($declared->downsize(false, 8, 'card'))->toBeFalse();
});

it('keeps a private media on the module with a focal point when the module makes the sizes', function (): void {
    $declared = ($this->make)(null, static fn (int $id): bool => true);

    expect($declared->downsize(false, 7, 'card')[0])->toBe('https://media.client.fr/'.($this->around)('card'));
});

it('builds the srcset of a cover with extra widths around the focal point, from the module', function (): void {
    $declared = ($this->make)(($this->imgproxy)());
    $banner = 'https://media.client.fr/'.($this->around)('banner');

    $sources = $declared->srcset([], [1200, 300], $banner, $this->meta, 7);

    expect(array_keys($sources))->toBe([600, 1200])
        ->and($sources[1200]['url'])->toBe($banner)
        ->and($sources[600]['url'])->toStartWith('https://media.client.fr/2026/10/photo-banner-600w-600x400-');
});

it('lists the files of a media, those around its point and those made before it was set, and only the first on demand', function (): void {
    $declared = ($this->make)();
    $point = $this->point;

    foreach ([($this->plain)('card'), ($this->around)('card'), ($this->plain)('hero')] as $file) {
        $this->disk->storage->write($file, 'x');
    }

    expect($declared->existingFiles($this->meta, $point))->toBe([($this->around)('card'), ($this->plain)('card'), ($this->plain)('hero')])
        ->and($declared->existingFiles($this->meta, $point, true))->toBe([($this->around)('card')])
        ->and($declared->existingFiles($this->meta))->toBe([($this->plain)('card'), ($this->plain)('hero')]);
});

it('makes the eager covers around the point at upload, and the other sizes as they were', function (): void {
    $meta = ($this->make)()->generate($this->meta, 7, 'create');

    expect(array_column($this->processor->made, 'focal', 'size'))->toBe(['card' => $this->point->fingerprint(), 'hero' => null, 'hero-640w' => null])
        ->and($meta['sizes']['card']['file'])->toBe(basename(($this->around)('card')))
        ->and($meta['sizes']['hero']['file'])->toBe(basename(($this->plain)('hero')));
});

it('makes a cover around a point on demand for the warmer, and names its file', function (): void {
    $declared = ($this->make)();
    $entry = $declared->make($this->meta, $this->sizes->get('card'), $this->point);

    expect($entry['file'])->toBe(basename(($this->around)('card')))
        ->and($declared->fileFor($this->meta, $this->sizes->get('card'), $this->point))->toBe(($this->around)('card'))
        ->and($declared->fileFor($this->meta, $this->sizes->get('card')))->toBe(($this->plain)('card'))
        ->and($declared->focalOf(7)?->equals($this->point))->toBeTrue()
        ->and($declared->focalOf(8))->toBeNull()
        ->and($this->disk->storage->exists(($this->around)('card')))->toBeTrue();
});

it('hooks the meta of the focal point to follow its changes', function (): void {
    Actions\expectAdded('update_post_meta')->once();
    Actions\expectAdded('delete_post_meta')->once();
    Actions\expectAdded('added_post_meta')->once();
    Actions\expectAdded('updated_post_meta')->once();
    Actions\expectAdded('deleted_post_meta')->once();

    ($this->make)()->register();
});

describe('a focal point that changes', function (): void {
    beforeEach(function (): void {
        $this->declared = ($this->make)();
        $this->previous = ($this->around)('card');
        $this->moved = new FocalPoint(0.9, 0.1);
        $this->movedFile = $this->sizes->names()->for('2026/10/photo.jpg', $this->sizes->get('card')->focusedOn($this->moved), 3000, 2000);
        $this->updated = null;
        Functions\when('wp_update_attachment_metadata')->alias(function (int $id, array $metadata): bool {
            $this->updated = [$id, $metadata];

            return true;
        });
        $this->disk->storage->write($this->previous, 'old crop');
        $this->disk->storage->write(($this->plain)('card'), 'plain crop');
    });

    it('deletes the files around the previous point and makes the eager ones around the new', function (): void {
        $this->declared->focalWillChange(1, 7, '_pollora_focal_point');
        $this->postMeta[7] = $this->moved->toArray();
        $this->declared->focalChanged(1, 7, '_pollora_focal_point');

        expect($this->disk->storage->exists($this->previous))->toBeFalse()
            ->and($this->disk->storage->exists($this->movedFile))->toBeTrue()
            ->and($this->disk->storage->exists(($this->plain)('card')))->toBeTrue()
            ->and($this->updated[0])->toBe(7)
            ->and($this->updated[1]['sizes']['card']['file'])->toBe(basename($this->movedFile));
    });

    it('deletes the files around a point that was removed, and keeps the plain ones', function (): void {
        $this->declared->focalWillChange([1], 7, '_pollora_focal_point');
        unset($this->postMeta[7]);
        $this->declared->focalChanged([1], 7, '_pollora_focal_point');

        expect($this->disk->storage->exists($this->previous))->toBeFalse()
            ->and($this->disk->storage->exists(($this->plain)('card')))->toBeTrue()
            ->and($this->updated[1]['sizes']['card']['file'])->toBe(basename(($this->plain)('card')));
    });

    it('does nothing for another meta, or for a media that is not an image', function (): void {
        $this->declared->focalWillChange(1, 7, '_wp_attached_file');
        $this->declared->focalChanged(1, 7, '_wp_attached_file');

        expect($this->disk->storage->exists($this->previous))->toBeTrue()
            ->and($this->updated)->toBeNull();

        Functions\when('wp_get_attachment_metadata')->justReturn(['file' => '2026/10/doc.pdf']);
        $this->declared->focalWillChange(1, 7, '_pollora_focal_point');
        $this->declared->focalChanged(1, 7, '_pollora_focal_point');

        expect($this->disk->storage->exists($this->previous))->toBeTrue()
            ->and($this->updated)->toBeNull();
    });
});

describe('what a change of focal point deletes', function (): void {
    beforeEach(function (): void {
        $this->declared = ($this->make)();
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
    });

    it('is nothing when the point is saved as it was', function (): void {
        $this->disk->storage->write(($this->around)('card'), 'crop');

        $this->declared->focalWillChange(1, 7, '_pollora_focal_point');
        $this->declared->focalChanged(1, 7, '_pollora_focal_point');

        expect($this->disk->storage->exists(($this->around)('card')))->toBeTrue();
    });

    it('is nothing for the files of another meta, nor of something that is not a media', function (mixed $id, string $key): void {
        $this->disk->storage->write(($this->around)('card'), 'crop');

        $this->declared->focalWillChange(1, $id, $key);
        unset($this->postMeta[7]);
        $this->declared->focalChanged(1, $id, $key);

        expect($this->disk->storage->exists(($this->around)('card')))->toBeTrue();
    })->with([
        'another meta' => [7, '_wp_attached_file'],
        'not a media' => ['seven', '_pollora_focal_point'],
    ]);

    it('takes a media id given as text, the way WordPress gives some', function (): void {
        $this->disk->storage->write(($this->around)('card'), 'crop');

        $this->declared->focalWillChange(1, '7', '_pollora_focal_point');
        unset($this->postMeta[7]);
        $this->declared->focalChanged(1, '7', '_pollora_focal_point');

        expect($this->disk->storage->exists(($this->around)('card')))->toBeFalse();
    });
});

it('lists no file of a media that is not an image it can make sizes of', function (array $metadata): void {
    $this->disk->storage->write(($this->plain)('card'), 'x');

    expect(($this->make)()->existingFiles($metadata))->toBe([]);
})->with([
    'a GIF' => [['file' => '2026/10/photo.gif', 'width' => 10, 'height' => 10]],
    'no dimensions' => [['file' => '2026/10/photo.jpg']],
    'no file' => [['width' => 10, 'height' => 10]],
]);

it('knows an image by its extension, in any case', function (string $file, bool $makeable): void {
    expect(($this->make)()->isMakeable(['file' => $file, 'width' => 10, 'height' => 10]))->toBe($makeable);
})->with([
    ['a.JPG', true], ['a.Png', true], ['a.webp', true], ['a.avif', true], ['a.GIF', false], ['a.pdf', false],
]);

it('lists a file once when two sizes would make the same one', function (): void {
    $sizes = new ImageSizes(fn (): string => 'secret-key');
    $sizes->add('card')->cover(600, 400);
    $declared = new DeclaredSizes($sizes, $this->config, $this->disk->storage, $this->processor);
    $file = $sizes->names()->for('2026/10/photo.jpg', $sizes->get('card'), 3000, 2000);
    $this->disk->storage->write($file, 'x');

    expect($declared->existingFiles($this->meta))->toBe([$file]);
});

it('keeps a crop made on demand when the focal point is saved without moving', function (): void {
    $declared = ($this->make)();
    Functions\when('wp_update_attachment_metadata')->justReturn(true);
    // `banner` is not eager: nothing would make it again
    $this->disk->storage->write(($this->around)('banner'), 'made on demand');

    $declared->focalWillChange(1, 7, '_pollora_focal_point');
    $declared->focalChanged(1, 7, '_pollora_focal_point');

    expect($this->disk->storage->exists(($this->around)('banner')))->toBeTrue();
});
