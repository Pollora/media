<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\Anchor;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Enums\OutputFormat;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\BunnyClasses;
use Pollora\Media\Domain\Services\ImgproxyPresets;

it('maps a size to the properties of a Bunny class', function (ImageSizeSpec $spec, array $properties): void {
    expect((new BunnyClasses)->properties($spec))->toBe($properties);
})->with([
    'cover is a crop to the ratio, then a width (inferred)' => [new ImageSizeSpec('card', Fit::Cover, 600, 400, OutputFormat::Webp, 75), ['aspect_ratio' => '3:2', 'format' => 'webp', 'quality' => '75', 'width' => '600']],
    'contain fits the box' => [new ImageSizeSpec('box', Fit::Contain, 800, 800), ['format' => 'webp', 'height' => '800', 'quality' => '80', 'width' => '800']],
    'scale by width alone' => [new ImageSizeSpec('hero', Fit::Scale, 1920, null, OutputFormat::Avif, 70), ['format' => 'avif', 'quality' => '70', 'width' => '1920']],
    'scale by height alone, original format, upscale' => [new ImageSizeSpec('tall', Fit::Scale, null, 500, OutputFormat::Original, 90, true), ['height' => '500', 'quality' => '90', 'upscaling' => 'resampling']],
]);

it('says what Bunny does not guarantee for a size: AVIF only above 4 megapixels, a cover is checked and says nothing', function (): void {
    $bunny = new BunnyClasses;

    expect($bunny->caveats(new ImageSizeSpec('hero', Fit::Scale, 1920, null, OutputFormat::Avif))[0])->toContain('4 megapixels')
        ->and($bunny->caveats(new ImageSizeSpec('card', Fit::Cover, 600, 400)))->toBe([])
        ->and($bunny->caveats(new ImageSizeSpec('hero', Fit::Scale, 1920, null)))->toBe([]);
});

it('maps a size to an imgproxy preset', function (ImageSizeSpec $spec, string $definition): void {
    expect((new ImgproxyPresets)->definition($spec))->toBe($definition);
})->with([
    'cover keeps the ratio of an undersized original' => [new ImageSizeSpec('card', Fit::Cover, 600, 400, OutputFormat::Webp, 75), 'card=resize:fill-down:600:400:0/quality:75'],
    'cover with upscale fills and enlarges' => [new ImageSizeSpec('card', Fit::Cover, 600, 400, OutputFormat::Webp, 75, true), 'card=resize:fill:600:400:1/quality:75'],
    'cover anchored to the top adds a gravity' => [new ImageSizeSpec('portrait', Fit::Cover, 400, 500, OutputFormat::Webp, 75, anchor: Anchor::Top), 'portrait=resize:fill-down:400:500:0/gravity:no/quality:75'],
    'cover anchored to a corner' => [new ImageSizeSpec('banner', Fit::Cover, 1600, 400, OutputFormat::Webp, 75, true, anchor: Anchor::TopLeft), 'banner=resize:fill:1600:400:1/gravity:nowe/quality:75'],
    'contain fits' => [new ImageSizeSpec('box', Fit::Contain, 800, 800), 'box=resize:fit:800:800:0/quality:80'],
    'scale by width has a zero height' => [new ImageSizeSpec('hero-640w', Fit::Scale, 640, null), 'hero-640w=resize:fit:640:0:0/quality:80'],
    'scale by height has a zero width, with upscale' => [new ImageSizeSpec('tall', Fit::Scale, null, 500, OutputFormat::Webp, 80, true), 'tall=resize:fit:0:500:1/quality:80'],
]);

it('turns a size name into a class name made of letters and digits', function (string $size, string $class): void {
    expect((new BunnyClasses)->className($size))->toBe($class);
})->with([['card', 'card'], ['hero-640w', 'hero640w'], ['my_size', 'mysize'], ['Wide_2x', 'wide2x']]);

it('gives every anchor the gravity imgproxy names it by', function (): void {
    expect(array_map(static fn (Anchor $anchor): string => $anchor->imgproxyGravity(), Anchor::cases()))
        ->toBe(['ce', 'no', 'so', 'we', 'ea', 'nowe', 'noea', 'sowe', 'soea']);
});

it('refuses for Bunny a cover that is not centered, and not a centered one', function (): void {
    $bunny = new BunnyClasses;
    $top = new ImageSizeSpec('portrait', Fit::Cover, 400, 500, anchor: Anchor::Top);

    expect($bunny->refusal('portrait', ['portrait'], $top))->toContain('anchored to the top')
        ->and($bunny->refusal('card', ['card'], new ImageSizeSpec('card', Fit::Cover, 600, 400)))->toBeNull()
        ->and($bunny->refusal('portrait', ['portrait']))->toBeNull();
});
