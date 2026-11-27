<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\Anchor;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\FocalPoint;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizeGeometry;

function spec(Fit $fit, ?int $width, ?int $height, bool $upscale = false): ImageSizeSpec
{
    return new ImageSizeSpec('card', $fit, $width, $height, upscale: $upscale);
}

it('crops to exactly the box when the original is big enough: cover', function (): void {
    expect((new SizeGeometry)->dimensions(spec(Fit::Cover, 600, 400), 3000, 2000))->toBe([600, 400])
        ->and((new SizeGeometry)->dimensions(spec(Fit::Cover, 600, 400), 1200, 1200))->toBe([600, 400]);
});

it('keeps the ratio of the box and never enlarges when the original is smaller: cover', function (): void {
    expect((new SizeGeometry)->dimensions(spec(Fit::Cover, 600, 400), 300, 300))->toBe([300, 200])
        ->and((new SizeGeometry)->dimensions(spec(Fit::Cover, 600, 400), 900, 300))->toBe([450, 300]);
});

it('enlarges a cover only when the size allows it', function (): void {
    expect((new SizeGeometry)->dimensions(spec(Fit::Cover, 600, 400, upscale: true), 300, 300))->toBe([600, 400]);
});

it('scales to a width or a height keeping the ratio, and never enlarges: scale', function (): void {
    $geometry = new SizeGeometry;

    expect($geometry->dimensions(spec(Fit::Scale, 1920, null), 4000, 3000))->toBe([1920, 1440])
        ->and($geometry->dimensions(spec(Fit::Scale, null, 500), 4000, 3000))->toBe([667, 500])
        ->and($geometry->dimensions(spec(Fit::Scale, 1920, null), 800, 600))->toBe([800, 600])
        ->and($geometry->dimensions(spec(Fit::Scale, 1920, null, upscale: true), 800, 600))->toBe([1920, 1440]);
});

it('fits inside the box without cropping: contain', function (): void {
    $geometry = new SizeGeometry;

    expect($geometry->dimensions(spec(Fit::Contain, 600, 400), 3000, 3000))->toBe([400, 400])
        ->and($geometry->dimensions(spec(Fit::Contain, 600, 400), 3000, 1000))->toBe([600, 200])
        ->and($geometry->dimensions(spec(Fit::Contain, 600, 400), 300, 200))->toBe([300, 200]);
});

it('gives the region of the original a cover keeps, centered', function (): void {
    $geometry = new SizeGeometry;

    expect($geometry->coverRegion(3000, 2000, 600, 400))->toBe(['x' => 0, 'y' => 0, 'width' => 3000, 'height' => 2000])
        ->and($geometry->coverRegion(2000, 2000, 600, 400))->toBe(['x' => 0, 'y' => 333, 'width' => 2000, 'height' => 1333])
        ->and($geometry->coverRegion(3000, 1000, 600, 400))->toBe(['x' => 750, 'y' => 0, 'width' => 1500, 'height' => 1000]);
});

it('refuses a size that has no dimension, a negative one, or a cover without both', function (): void {
    expect(fn () => spec(Fit::Scale, null, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => spec(Fit::Cover, 600, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => spec(Fit::Contain, 0, 400))->toThrow(InvalidArgumentException::class);
});

it('places the region of a cover by its anchor, on the axis that has room to spare', function (Anchor $anchor, array $landscape, array $portrait): void {
    $geometry = new SizeGeometry;

    expect($geometry->coverRegion(3000, 1000, 600, 400, $anchor))->toBe($landscape)
        ->and($geometry->coverRegion(2000, 3000, 600, 400, $anchor))->toBe($portrait);
})->with([
    'center' => [Anchor::Center, ['x' => 750, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 833, 'width' => 2000, 'height' => 1333]],
    'top' => [Anchor::Top, ['x' => 750, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 0, 'width' => 2000, 'height' => 1333]],
    'bottom' => [Anchor::Bottom, ['x' => 750, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 1667, 'width' => 2000, 'height' => 1333]],
    'left' => [Anchor::Left, ['x' => 0, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 833, 'width' => 2000, 'height' => 1333]],
    'right' => [Anchor::Right, ['x' => 1500, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 833, 'width' => 2000, 'height' => 1333]],
    'top left' => [Anchor::TopLeft, ['x' => 0, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 0, 'width' => 2000, 'height' => 1333]],
    'bottom right' => [Anchor::BottomRight, ['x' => 1500, 'y' => 0, 'width' => 1500, 'height' => 1000], ['x' => 0, 'y' => 1667, 'width' => 2000, 'height' => 1333]],
]);

it('keeps the whole original, whatever the anchor, when it has the ratio of the box already', function (): void {
    expect((new SizeGeometry)->coverRegion(3000, 2000, 600, 400, Anchor::BottomRight))->toBe(['x' => 0, 'y' => 0, 'width' => 3000, 'height' => 2000]);
});

it('does not change the dimensions of a size with its anchor', function (): void {
    $centered = new ImageSizeSpec('card', Fit::Cover, 600, 400);
    $anchored = new ImageSizeSpec('card', Fit::Cover, 600, 400, anchor: Anchor::Top);

    expect((new SizeGeometry)->dimensions($anchored, 3000, 2000))->toBe((new SizeGeometry)->dimensions($centered, 3000, 2000))
        ->and((new SizeGeometry)->dimensions($anchored, 300, 300))->toBe((new SizeGeometry)->dimensions($centered, 300, 300));
});

it('refuses an anchor on a size that is not a cover', function (): void {
    expect(fn () => new ImageSizeSpec('hero', Fit::Scale, 1920, null, anchor: Anchor::Top))->toThrow(InvalidArgumentException::class, 'only means something for a cover')
        ->and(fn () => new ImageSizeSpec('box', Fit::Contain, 800, 800, anchor: Anchor::Left))->toThrow(InvalidArgumentException::class);
});

it('keeps the fingerprint of a centered size as it was before anchors, and changes it with the anchor', function (): void {
    $centered = new ImageSizeSpec('card', Fit::Cover, 600, 400);

    expect($centered->fingerprint())->toBe('cover|600|400|webp|80|0')
        ->and($centered->version())->toBe(substr(sha1('cover|600|400|webp|80|0'), 0, 8))
        ->and((new ImageSizeSpec('card', Fit::Cover, 600, 400, anchor: Anchor::Top))->version())->not->toBe($centered->version())
        ->and((new ImageSizeSpec('card', Fit::Cover, 600, 400, anchor: Anchor::Top))->fingerprint())->not->toBe((new ImageSizeSpec('card', Fit::Cover, 600, 400, anchor: Anchor::Bottom))->fingerprint());
});

it('does not resize an original whose dimensions are unknown, and never divides by zero', function (Fit $fit, ?int $width, ?int $height, int $originalWidth, int $originalHeight): void {
    expect((new SizeGeometry)->dimensions(spec($fit, $width, $height), $originalWidth, $originalHeight))->toBe([$originalWidth, $originalHeight]);
})->with([
    'scale by width, 0 x 0' => [Fit::Scale, 300, null, 0, 0],
    'scale by height, 0 x 0' => [Fit::Scale, null, 300, 0, 0],
    'scale in a box, width only unknown' => [Fit::Scale, 300, 200, 0, 800],
    'contain, 0 x 0' => [Fit::Contain, 800, 200, 0, 0],
    'contain, height only unknown' => [Fit::Contain, 800, 200, 1200, 0],
]);

it('keeps the whole of an original whose dimensions are unknown as the region of a cover', function (): void {
    expect((new SizeGeometry)->coverRegion(0, 0, 600, 400))->toBe(['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0])
        ->and((new SizeGeometry)->coverRegion(800, 0, 600, 400))->toBe(['x' => 0, 'y' => 0, 'width' => 800, 'height' => 0]);
});

/*
 * The URL of a size states its width and height, and the route refuses a name whose figures differ:
 * the rounding is part of the contract with the files already made.
 */
it('rounds the dimensions of a size to the nearest pixel, never to zero', function (ImageSizeSpec $spec, array $original, array $expected): void {
    expect((new SizeGeometry)->dimensions($spec, ...$original))->toBe($expected);
})->with([
    'scale, rounding down' => [new ImageSizeSpec('s', Fit::Scale, 100, null), [300, 100], [100, 33]],
    'scale, rounding up' => [new ImageSizeSpec('s', Fit::Scale, 100, null), [300, 200], [100, 67]],
    'scale by height, width rounding down' => [new ImageSizeSpec('s', Fit::Scale, null, 100), [100, 300], [33, 100]],
    'scale by height, width rounding up' => [new ImageSizeSpec('s', Fit::Scale, null, 100), [200, 300], [67, 100]],
    'scale to the tighter of width and height' => [new ImageSizeSpec('s', Fit::Scale, 100, 100), [400, 200], [100, 50]],
    'scale to the tighter of height and width' => [new ImageSizeSpec('s', Fit::Scale, 100, 100), [200, 400], [50, 100]],
    'a very wide image keeps a pixel of height' => [new ImageSizeSpec('s', Fit::Scale, 10, null), [1000, 1], [10, 1]],
    'a very tall image keeps a pixel of width' => [new ImageSizeSpec('s', Fit::Scale, null, 10), [1, 1000], [1, 10]],
    'an original of one pixel is scaled' => [new ImageSizeSpec('s', Fit::Scale, 10, null, upscale: true), [1, 1], [10, 10]],
    'unknown height' => [new ImageSizeSpec('s', Fit::Scale, 10, null), [100, -1], [100, 0]],
    'unknown width' => [new ImageSizeSpec('s', Fit::Contain, 10, 10), [-1, 100], [0, 100]],
    'a small cover, rounding down' => [new ImageSizeSpec('s', Fit::Cover, 300, 100), [100, 100], [100, 33]],
    'a small cover, rounding up' => [new ImageSizeSpec('s', Fit::Cover, 300, 200), [100, 100], [100, 67]],
    'a small cover, width rounding down' => [new ImageSizeSpec('s', Fit::Cover, 100, 300), [100, 100], [33, 100]],
    'a small cover, width rounding up' => [new ImageSizeSpec('s', Fit::Cover, 200, 300), [100, 100], [67, 100]],
    'a cover of a sliver keeps a pixel of width' => [new ImageSizeSpec('s', Fit::Cover, 100, 1000), [100, 1], [1, 1]],
    'a cover of a sliver keeps a pixel of height' => [new ImageSizeSpec('s', Fit::Cover, 1000, 100), [1, 100], [1, 1]],
]);

it('rounds the region of a cover to the nearest pixel', function (array $original, array $box, array $expected): void {
    $region = (new SizeGeometry)->coverRegion(...$original, ...$box);

    expect([$region['width'], $region['height']])->toBe($expected);
})->with([
    'width rounded down' => [[1000, 100], [100, 30], [333, 100]],
    'width rounded up' => [[1000, 200], [100, 30], [667, 200]],
    'height rounded down' => [[100, 1000], [300, 100], [100, 33]],
    'height rounded up' => [[200, 1000], [300, 100], [200, 67]],
]);

it('takes nothing from an original whose dimensions are negative', function (): void {
    expect((new SizeGeometry)->coverRegion(-5, -7, 10, 10))->toBe(['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0]);
});

it('centres a crop on the focal point to the nearest pixel', function (): void {
    $region = (new SizeGeometry)->coverRegion(1000, 100, 100, 100, focal: new FocalPoint(0.504, 0.5));
    $half = (new SizeGeometry)->coverRegion(1000, 101, 101, 101, focal: new FocalPoint(0.5, 0.5));

    expect($region['x'])->toBe(454)->and($half['x'])->toBe(450);
});
