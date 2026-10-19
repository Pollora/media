<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\Fit;
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
