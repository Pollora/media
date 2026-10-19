<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizedFileName;

beforeEach(function (): void {
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
});

it('declares sizes fluently, with the options of the specification', function (): void {
    $this->sizes->add('card')->cover(600, 400)->webp(75);
    $this->sizes->add('hero')->scale(width: 1920)->webp(70)->widths([640, 1024, 1440]);
    $this->sizes->add('thumb')->cover(150, 150)->eager();
    $this->sizes->add('box')->contain(800, 800)->avif()->upscale();
    $this->sizes->add('raw')->scale(height: 300)->original();

    $all = $this->sizes->all();

    expect(array_keys($all))->toBe(['card', 'hero', 'thumb', 'box', 'raw'])
        ->and($all['card'])->toEqual(new ImageSizeSpec('card', Fit::Cover, 600, 400, OutputFormat::Webp, 75))
        ->and($all['hero']->width)->toBe(1920)->and($all['hero']->height)->toBeNull()
        ->and($all['hero']->widths)->toBe([640, 1024, 1440])
        ->and($all['thumb']->eager)->toBeTrue()
        ->and($all['box']->format)->toBe(OutputFormat::Avif)->and($all['box']->quality)->toBe(80)->and($all['box']->upscale)->toBeTrue()
        ->and($all['raw']->format)->toBe(OutputFormat::Original);
});

it('is WebP at quality 80, not eager and not enlarging, until told otherwise', function (): void {
    $this->sizes->add('plain')->cover(300, 300);

    $spec = $this->sizes->get('plain');

    expect($spec->format)->toBe(OutputFormat::Webp)->and($spec->quality)->toBe(80)
        ->and($spec->eager)->toBeFalse()->and($spec->upscale)->toBeFalse()->and($spec->widths)->toBe([]);
});

it('takes the last word on a size declared twice', function (): void {
    $this->sizes->add('card')->cover(600, 400);
    $this->sizes->add('card')->cover(300, 200);

    expect($this->sizes->get('card')->width)->toBe(300);
});

it('keeps a declaration it cannot make out of the sizes, and says why', function (): void {
    $this->sizes->add('ok')->cover(100, 100);
    $this->sizes->add('empty');
    $this->sizes->add('half')->cover(600);
    $this->sizes->add('Bad Name')->cover(10, 10);

    expect(array_keys($this->sizes->all()))->toBe(['ok'])
        ->and($this->sizes->refusals())->toHaveCount(3)
        ->and($this->sizes->refusals()[0])->toContain('empty')
        ->and($this->sizes->refusals()[2])->toContain('Bad Name');
});

it('derives one bounded size per extra width, named after the size and the width', function (): void {
    $this->sizes->add('hero')->scale(width: 1920)->webp(70)->widths([640, 1024])->upscale();

    $variants = $this->sizes->variantsOf($this->sizes->get('hero'));

    expect(array_keys($variants))->toBe(['hero-640w', 'hero-1024w'])
        ->and($variants['hero-640w']->fit)->toBe(Fit::Scale)
        ->and($variants['hero-640w']->width)->toBe(640)
        ->and($variants['hero-640w']->quality)->toBe(70)
        ->and($variants['hero-640w']->upscale)->toBeFalse();
});

it('names a sized file after the original, the size, its dimensions and a signature of both', function (): void {
    $name = new SizedFileName(fn (): string => 'secret-key');
    $spec = new ImageSizeSpec('card', Fit::Cover, 600, 400);

    $file = $name->for('2026/10/photo.jpg', $spec, 3000, 2000);

    expect($file)->toMatch('#^2026/10/photo-card-600x400-[0-9a-f]{12}\.webp$#')
        ->and($name->for('2026/10/photo.jpg', $spec, 3000, 2000))->toBe($file)
        ->and($name->for('2026/10/photo.png', $spec, 3000, 2000))->not->toBe($file)
        ->and($name->for('photo.jpg', new ImageSizeSpec('card', Fit::Cover, 600, 400, quality: 70), 3000, 2000))->toMatch('#^photo-card-600x400-[0-9a-f]{12}\.webp$#');
});

it('changes the name when the size, the original or the key changes, and keeps the extension for original()', function (): void {
    $name = new SizedFileName(fn (): string => 'secret-key');
    $card = new ImageSizeSpec('card', Fit::Cover, 600, 400);
    $base = $name->for('a/photo.jpg', $card, 3000, 2000);

    expect($name->for('a/photo.jpg', new ImageSizeSpec('card', Fit::Cover, 600, 400, quality: 75), 3000, 2000))->not->toBe($base)
        ->and($name->for('a/other.jpg', $card, 3000, 2000))->not->toBe($base)
        ->and((new SizedFileName(fn (): string => 'another-key'))->for('a/photo.jpg', $card, 3000, 2000))->not->toBe($base)
        ->and($name->for('a/photo.JPG', new ImageSizeSpec('raw', Fit::Scale, 800, null, OutputFormat::Original), 3000, 2000))->toEndWith('.jpg')
        ->and($name->for('a/photo.jpg', new ImageSizeSpec('x', Fit::Scale, 800, null, OutputFormat::Avif), 3000, 2000))->toEndWith('.avif');
});

it('recognises the files it names, and the signature they carry', function (): void {
    $name = new SizedFileName(fn (): string => 'secret-key');
    $spec = new ImageSizeSpec('card', Fit::Cover, 600, 400);
    $file = $name->for('2026/10/my-photo.jpg', $spec, 3000, 2000);

    $parsed = $name->parse($file);

    expect($parsed)->toMatchArray(['directory' => '2026/10', 'stem' => 'my-photo', 'name' => 'card', 'width' => 600, 'height' => 400, 'extension' => 'webp'])
        ->and($parsed['signature'])->toBe($name->signature('2026/10/my-photo.jpg', $spec))
        ->and($name->parse('2026/10/my-photo.jpg'))->toBeNull()
        ->and($name->parse('2026/10/photo-150x150.jpg'))->toBeNull();
});
