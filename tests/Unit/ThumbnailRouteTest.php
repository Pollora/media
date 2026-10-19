<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Application\Services\ThumbnailService;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\Locking\FlockGenerationLock;
use Pollora\Media\Infrastructure\WordPress\ThumbnailRoute;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\PngHeader;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400);
    $this->disk->storage->write('2026/10/photo.png', PngHeader::bytes(3000, 2000));
    $this->lockDir = sys_get_temp_dir().'/pollora-media-route-locks-'.bin2hex(random_bytes(4));
    $service = new ThumbnailService(
        $this->disk->storage,
        $this->sizes,
        new FakeImageProcessor($this->disk->storage),
        new FlockGenerationLock($this->lockDir),
        new ThumbnailLimits(wait: 0),
        static fn (string $key): bool => false,
    );
    $this->route = new ThumbnailRoute($service, new MediaConfig('https://site.test/content/uploads'));
    $this->file = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000);
});

afterEach(function (): void {
    foreach (glob($this->lockDir.'/*') ?: [] as $file) {
        unlink($file);
    }
    is_dir($this->lockDir) && rmdir($this->lockDir);
});

it('hooks WordPress as early as it goes, before it runs its query', function (): void {
    Actions\expectAdded('parse_request')->once();

    $this->route->register();
});

it('takes the path of the library out of the URL path, whatever the query string', function (): void {
    expect($this->route->keyOf('/content/uploads/2026/10/photo-card-600x400-0123456789ab.webp?x=1'))->toBe('2026/10/photo-card-600x400-0123456789ab.webp')
        ->and($this->route->keyOf('/content/uploads/2026/10/a%20b-card-600x400-0123456789ab.webp'))->toBe('2026/10/a b-card-600x400-0123456789ab.webp')
        ->and($this->route->keyOf('/2026/10/photo-card-600x400-0123456789ab.webp'))->toBe('2026/10/photo-card-600x400-0123456789ab.webp');
});

it('does not look at a request that is not a size: a page, an asset, a plain image', function (string $path): void {
    expect($this->route->keyOf($path))->toBeNull();
})->with([
    'a page' => ['/shop/'],
    'a plain image' => ['/content/uploads/2026/10/photo.jpg'],
    'a size WordPress made' => ['/content/uploads/2026/10/photo-300x200.jpg'],
    'a script' => ['/content/themes/x/app-card-600x400-0123456789ab.js'],
    'an empty path' => [''],
]);

it('makes and answers for a size that was asked for', function (): void {
    $result = $this->route->resolve('/content/uploads/'.$this->file);

    expect($result?->status)->toBe(200)->and($this->disk->storage->exists($this->file))->toBeTrue();
});

it('leaves WordPress to answer when the module has nothing to say', function (): void {
    expect($this->route->resolve('/content/uploads/2026/10/photo-card-600x400-0123456789ab.webp')?->status)->toBe(404)
        ->and($this->route->resolve('/shop/'))->toBeNull();
});
