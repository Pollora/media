<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\DeclaredSizes;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->disk->storage->write('2026/10/photo.jpg', 'original');
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75)->eager();
    $this->sizes->add('hero')->scale(width: 1920)->widths([640, 1024])->eager();
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $this->declared = new DeclaredSizes($this->sizes, new MediaConfig('https://media.client.fr'), $this->disk->storage, $this->processor);
    $this->meta = ['file' => '2026/10/photo.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []];
    $this->cardFile = $this->sizes->names()->for('2026/10/photo.jpg', $this->sizes->get('card'), 3000, 2000);
});

it('hooks the sizes WordPress makes, the metadata, the URL of a size and the srcset', function (): void {
    Filters\expectAdded('intermediate_image_sizes_advanced')->once();
    Filters\expectAdded('wp_generate_attachment_metadata')->once();
    Filters\expectAdded('image_downsize')->once();
    Filters\expectAdded('wp_calculate_image_srcset')->once();

    $this->declared->register();
});

it('takes the declared sizes out of what WordPress makes, and leaves the others', function (): void {
    $wordpress = ['thumbnail' => [], 'card' => [], 'hero' => [], 'hero-640w' => [], 'woocommerce_single' => []];

    expect(array_keys($this->declared->withoutDeclared($wordpress)))->toBe(['thumbnail', 'woocommerce_single']);
});

it('makes every declared size and its widths when an image is uploaded, and records them in the metadata', function (): void {
    $meta = $this->declared->generate($this->meta, 7, 'create');

    expect($this->processor->made)->toHaveCount(4)
        ->and(array_column($this->processor->made, 'size'))->toBe(['card', 'hero', 'hero-640w', 'hero-1024w'])
        ->and($meta['sizes']['card']['file'])->toBe(basename($this->cardFile))
        ->and($meta['sizes']['card'])->toMatchArray(['width' => 600, 'height' => 400, 'mime-type' => 'image/webp'])
        ->and($meta['sizes']['hero']['width'])->toBe(1920)
        ->and($meta['sizes']['hero-640w']['width'])->toBe(640)
        ->and($this->disk->storage->exists($this->cardFile))->toBeTrue();
});

it('does nothing on an update of the metadata, for a file that is not an image, or with no size declared', function (): void {
    $this->declared->generate($this->meta, 7, 'update');
    $this->declared->generate(['file' => '2026/10/doc.pdf'], 7, 'create');
    (new DeclaredSizes(new ImageSizes(fn (): string => 'k'), new MediaConfig('https://m.test'), $this->disk->storage, $this->processor))->generate($this->meta, 7, 'create');

    expect($this->processor->made)->toBe([]);
});

it('keeps going when one size cannot be made, and leaves it out of the metadata', function (): void {
    $processor = new FakeImageProcessor($this->disk->storage, failing: 'card');
    $declared = new DeclaredSizes($this->sizes, new MediaConfig('https://media.client.fr'), $this->disk->storage, $processor);

    $meta = $declared->generate($this->meta, 7, 'create');

    expect($meta['sizes'])->not->toHaveKey('card')->and($meta['sizes'])->toHaveKey('hero');
});

it('does not make a file that is already on the disk', function (): void {
    $this->disk->storage->write($this->cardFile, 'already there');

    $this->declared->generate($this->meta, 7, 'create');

    expect(array_column($this->processor->made, 'size'))->not->toContain('card');
});

it('gives the URL of a size from the metadata alone, whether or not its file exists', function (): void {
    Functions\when('wp_get_attachment_metadata')->justReturn($this->meta);

    $image = $this->declared->downsize(false, 7, 'card');

    expect($image)->toBe(['https://media.client.fr/'.$this->cardFile, 600, 400, true])
        ->and($this->disk->storage->exists($this->cardFile))->toBeFalse();
});

it('leaves the sizes it does not know to WordPress', function (): void {
    Functions\when('wp_get_attachment_metadata')->justReturn($this->meta);

    expect($this->declared->downsize(false, 7, 'thumbnail'))->toBeFalse()
        ->and($this->declared->downsize(false, 7, [300, 200]))->toBeFalse()
        ->and($this->declared->downsize(['kept'], 7, 'card'))->toBe(['kept']);
});

it('gives no URL when the media has no dimensions to compute a size from', function (): void {
    Functions\when('wp_get_attachment_metadata')->justReturn(['file' => '2026/10/doc.pdf']);

    expect($this->declared->downsize(false, 7, 'card'))->toBeFalse();
});

it('builds the srcset of a size from its extra widths, without reading a file', function (): void {
    $heroFile = $this->sizes->names()->for('2026/10/photo.jpg', $this->sizes->get('hero'), 3000, 2000);

    $sources = $this->declared->srcset([], [1920, 1280], 'https://media.client.fr/'.$heroFile, $this->meta, 7);

    expect(array_keys($sources))->toBe([640, 1024, 1920])
        ->and($sources[640]['url'])->toStartWith('https://media.client.fr/2026/10/photo-hero-640w-640x427-')
        ->and($sources[640]['descriptor'])->toBe('w')
        ->and($sources[640]['value'])->toBe(640)
        ->and($sources[1920]['url'])->toBe('https://media.client.fr/'.$heroFile);
});

it('leaves the srcset of an image that is not one of its sizes, or of a size with no extra width, as it is', function (): void {
    $cardUrl = 'https://media.client.fr/'.$this->cardFile;

    expect($this->declared->srcset(['kept'], [1, 1], 'https://media.client.fr/2026/10/photo-300x200.jpg', $this->meta, 7))->toBe(['kept'])
        ->and($this->declared->srcset(['kept'], [600, 400], $cardUrl, $this->meta, 7))->toBe(['kept'])
        ->and($this->declared->srcset(false, [1, 1], 'x', $this->meta, 7))->toBeFalse();
});

it('serves the original for a GIF, which a resize would stop animating, and makes no size of it', function (): void {
    $meta = ['file' => '2026/10/loop.gif', 'width' => 400, 'height' => 300, 'sizes' => []];
    Functions\when('wp_get_attachment_metadata')->justReturn($meta);

    expect($this->declared->downsize(false, 8, 'card'))->toBe(['https://media.client.fr/2026/10/loop.gif', 400, 300, false])
        ->and($this->declared->generate($meta, 8, 'create'))->toBe($meta)
        ->and($this->processor->made)->toBe([]);
});

it('makes at upload only the sizes declared eager, the others wait for their first request', function (): void {
    $sizes = new ImageSizes(fn (): string => 'secret-key');
    $sizes->add('card')->cover(600, 400)->eager();
    $sizes->add('lazy')->cover(300, 300);
    $processor = new FakeImageProcessor($this->disk->storage);
    $declared = new DeclaredSizes($sizes, new MediaConfig('https://media.client.fr'), $this->disk->storage, $processor);

    $meta = $declared->generate($this->meta, 7, 'create');

    expect(array_column($processor->made, 'size'))->toBe(['card'])
        ->and($meta['sizes'])->not->toHaveKey('lazy');
});
