<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\DeclaredSizes;
use Pollora\Media\Infrastructure\WordPress\SizeWarmer;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400);
    $this->sizes->add('wide')->scale(width: 1600);
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $declared = new DeclaredSizes($this->sizes, new MediaConfig('https://media.client.fr'), $this->disk->storage, $this->processor);
    $this->warmer = new SizeWarmer($declared, $this->disk->storage);
    $this->metadata = [
        10 => ['file' => '2026/10/a.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []],
        11 => ['file' => '2026/10/b.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []],
        12 => ['file' => '2026/10/doc.pdf'],
    ];
    $this->updated = [];
    Functions\when('get_posts')->alias(fn (array $args): array => (($args['paged'] ?? 1) === 1) ? [10, 11, 12] : []);
    Functions\when('wp_get_attachment_metadata')->alias(fn (int $id) => $this->metadata[$id]);
    Functions\when('wp_update_attachment_metadata')->alias(function (int $id, array $data): bool {
        $this->updated[$id] = $data;

        return true;
    });
});

it('makes the missing files of every declared size for the images of the library, and records them', function (): void {
    $report = $this->warmer->warm();

    expect($report['checked'])->toBe(2)
        ->and($report['made'])->toBe(4)
        ->and($report['failed'])->toBe(0)
        ->and(array_keys($this->updated))->toBe([10, 11])
        ->and(array_keys($this->updated[10]['sizes']))->toBe(['card', 'wide']);
});

it('does not remake a file that is there, and says nothing of what was fine', function (): void {
    $this->warmer->warm();
    $this->processor->made = [];
    $this->updated = [];

    $report = $this->warmer->warm();

    expect($report['made'])->toBe(0)->and($this->processor->made)->toBe([])->and($this->updated)->toBe([]);
});

it('warms one size only when asked', function (): void {
    $report = $this->warmer->warm(only: 'card');

    expect($report['made'])->toBe(2)->and(array_unique(array_column($this->processor->made, 'size')))->toBe(['card']);
});

it('counts a size that cannot be made, and goes on', function (): void {
    $processor = new FakeImageProcessor($this->disk->storage, failing: 'wide');
    $warmer = new SizeWarmer(new DeclaredSizes($this->sizes, new MediaConfig('https://media.client.fr'), $this->disk->storage, $processor), $this->disk->storage);

    $report = $warmer->warm();

    expect($report['failed'])->toBe(2)->and($report['made'])->toBe(2);
});

it('refuses a size that is not declared', function (): void {
    expect(fn () => $this->warmer->warm(only: 'nope'))->toThrow(InvalidArgumentException::class, 'nope');
});

it('only looks at the images uploaded since a date, when given one', function (): void {
    $seen = null;
    Functions\when('get_posts')->alias(function (array $args) use (&$seen): array {
        $seen = $args['date_query'] ?? null;

        return [];
    });

    $this->warmer->warm(since: '2026-01-01');

    expect($seen)->toBe([['after' => '2026-01-01']]);
});
