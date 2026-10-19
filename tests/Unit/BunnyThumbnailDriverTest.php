<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;

beforeEach(function (): void {
    $this->driver = new BunnyThumbnailDriver(new MediaConfig('https://media.client.fr'));
    $this->card = new ImageSizeSpec('card', Fit::Cover, 600, 400);
});

it('is the URL of the original and the name of the class', function (): void {
    expect($this->driver->url('2026/10/photo.jpg', $this->card, 3000, 2000))->toBe('https://media.client.fr/2026/10/photo.jpg?class=card');
});

it('does not make files, and refuses no size', function (): void {
    expect($this->driver->makesFiles())->toBeFalse()->and($this->driver->refusal($this->card))->toBeNull();
});

it('finds the size a URL it made stands for, and nothing else', function (): void {
    expect($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?class=hero-640w'))->toBe('hero-640w')
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg'))->toBeNull()
        ->and($this->driver->sizeOf('https://media.client.fr/2026/10/photo.jpg?width=300'))->toBeNull()
        ->and($this->driver->sizeOf('https://other.test/photo.jpg?class=card'))->toBeNull()
        ->and($this->driver->sizeOf('https://media.client.fr/photo.jpg?class=Not%20a%20size'))->toBeNull();
});
