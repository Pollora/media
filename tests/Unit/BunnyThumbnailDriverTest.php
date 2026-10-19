<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;

beforeEach(function (): void {
    $this->sizes = new ImageSizes(fn (): string => 'k');
    $this->sizes->add('card')->cover(600, 400);
    $this->sizes->add('hero')->scale(width: 1920)->widths([640, 1024]);
    $this->sizes->add('my_size')->scale(width: 100);
    $this->driver = new BunnyThumbnailDriver(new MediaConfig('https://media.client.fr'), $this->sizes);
    $this->card = $this->sizes->get('card');
});

it('is the URL of the original and the name of the class', function (): void {
    expect($this->driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))->toBe('https://media.client.fr/2026/10/photo.jpg?class=card');
});

it('keeps only letters and digits in the class, which is all Bunny accepts (checked against the API: "Invalid class name")', function (): void {
    $variant = $this->sizes->withVariants()['hero-640w'];

    expect($this->driver->url('a.jpg', $variant, 3000, 2000))->toEndWith('?class=hero640w')
        ->and($this->driver->url('a.jpg', $this->sizes->get('my_size'), 3000, 2000))->toEndWith('?class=mysize');
});

it('does not make files', function (): void {
    expect($this->driver->makesFiles())->toBeFalse();
});

it('finds the declared size a class stands for, and nothing else', function (): void {
    expect($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?class=hero640w'))->toBe('hero-640w')
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?class=mysize'))->toBe('my_size')
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?class=card'))->toBe('card')
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?class=nope'))->toBeNull()
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg'))->toBeNull()
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?width=300'))->toBeNull()
        ->and($this->driver->sizeOf('https://other.test/photo.jpg?class=card'))->toBeNull();
});

it('refuses the second of two sizes that would get the same class', function (): void {
    $this->sizes->add('mysize')->scale(width: 200);
    $this->sizes->add('hero640w')->scale(width: 300);

    expect($this->driver->refusal($this->sizes->get('my_size')))->toBeNull()
        ->and($this->driver->refusal($this->sizes->get('mysize')))->toContain('"my_size" already has')
        ->and($this->driver->refusal($this->sizes->get('hero640w')))->toContain('"hero-640w"')
        ->and($this->driver->sizeOf('https://media.client.fr/a.jpg?class=mysize'))->toBe('my_size');
});

it('refuses a size with no letter or digit', function (): void {
    $this->sizes->add('_')->scale(width: 100);

    expect($this->driver->refusal(new ImageSizeSpec('_', Fit::Scale, 100, null)))->toContain('letters and digits');
});
