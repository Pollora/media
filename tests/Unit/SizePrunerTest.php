<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\SizePruner;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400);
    $this->names = $this->sizes->names();
    $this->current = $this->names->for('2026/10/photo.jpg', $this->sizes->get('card'), 3000, 2000);
    $this->stale = '2026/10/photo-card-600x400-'.str_repeat('a', 12).'.webp';
    $this->removed = '2026/10/photo-banner-900x300-'.str_repeat('b', 12).'.webp';
    foreach (['2026/10/photo.jpg', $this->current, $this->stale, $this->removed, '2026/10/photo-150x150.jpg', '2026/10/orphan-card-600x400-'.str_repeat('c', 12).'.webp'] as $file) {
        $this->disk->storage->write($file, 'x');
    }
});

it('finds the sizes whose signature matches no declared size, and the sizes no longer declared', function (): void {
    $found = (new SizePruner($this->disk->storage, $this->sizes))->stale();

    expect($found)->toBe([$this->removed, $this->stale]);
});

it('keeps the current sizes, the files WordPress named, and a file whose original is gone', function (): void {
    $found = (new SizePruner($this->disk->storage, $this->sizes))->stale();

    expect($found)->not->toContain($this->current)
        ->and($found)->not->toContain('2026/10/photo-150x150.jpg')
        ->and($found)->not->toContain('2026/10/orphan-card-600x400-'.str_repeat('c', 12).'.webp');
});

it('deletes them only when told to, and says what it did', function (): void {
    $pruner = new SizePruner($this->disk->storage, $this->sizes);

    $dry = $pruner->prune(dryRun: true);
    expect($dry)->toBe([$this->removed, $this->stale])->and($this->disk->storage->exists($this->stale))->toBeTrue();

    $done = $pruner->prune(dryRun: false);
    expect($done)->toBe([$this->removed, $this->stale])
        ->and($this->disk->storage->exists($this->stale))->toBeFalse()
        ->and($this->disk->storage->exists($this->removed))->toBeFalse()
        ->and($this->disk->storage->exists($this->current))->toBeTrue()
        ->and($this->disk->storage->exists('2026/10/photo.jpg'))->toBeTrue();
});

it('treats the bounded size of an extra width like its size', function (): void {
    $this->sizes->add('hero')->scale(width: 1920)->widths([640]);
    $variant = $this->names->for('2026/10/photo.jpg', $this->sizes->variantsOf($this->sizes->get('hero'))['hero-640w'], 3000, 2000);
    $this->disk->storage->write($variant, 'x');

    expect((new SizePruner($this->disk->storage, $this->sizes))->stale())->not->toContain($variant);
});

it('keeps the sizes of the previous original of an edited image, which Restore image goes back to', function (): void {
    $this->disk->storage->write('2026/10/photo-e1729000000.jpg', 'edited');
    $edited = $this->names->for('2026/10/photo-e1729000000.jpg', $this->sizes->get('card'), 2000, 2000);
    $this->disk->storage->write($edited, 'x');

    $found = (new SizePruner($this->disk->storage, $this->sizes))->stale();

    expect($found)->not->toContain($edited)->and($found)->not->toContain($this->current);
});
