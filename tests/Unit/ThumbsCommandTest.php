<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\SizePruner;
use Pollora\Media\Application\Services\ThumbnailExporter;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;
use Pollora\Media\Infrastructure\ThumbnailDrivers;
use Pollora\Media\Infrastructure\WordPress\DeclaredSizes;
use Pollora\Media\Infrastructure\WordPress\SizeWarmer;
use Pollora\Media\Infrastructure\WordPress\ThumbsCommand;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\FakePullZone;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    WP_CLI::reset();
    $this->disk = new TemporaryStorage;
    $this->config = new MediaConfig('https://media.client.fr');
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400);
    $this->pullZone = new FakePullZone;
    $this->command = function (ThumbnailDriverSettings $settings = new ThumbnailDriverSettings): ThumbsCommand {
        $driver = ThumbnailDrivers::make($settings, $this->sizes, $this->config);
        $declared = new DeclaredSizes($this->sizes, $this->config, $this->disk->storage, new FakeImageProcessor($this->disk->storage), $driver);

        return new ThumbsCommand(
            new SizeWarmer($declared, $this->disk->storage),
            new SizePruner($this->disk->storage, $this->sizes),
            $driver,
            new ThumbnailExporter($this->sizes, $settings, new ThumbnailLimits, $this->config, $this->pullZone),
        );
    };
    $this->bunny = new ThumbnailDriverSettings(driver: 'bunny', bunnyPullZone: '1234', bunnyApiKey: 'key-1');
    Functions\when('get_posts')->alias(fn (array $args): array => (($args['paged'] ?? 1) === 1) ? [10] : []);
    Functions\when('wp_get_attachment_metadata')->justReturn(['file' => '2026/10/a.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => []]);
    Functions\when('wp_update_attachment_metadata')->justReturn(true);
});

it('warms the declared sizes of the library and reports the count', function (): void {
    ($this->command)()->warm([], []);

    expect(WP_CLI::messages('log'))->toBe(['1 image(s) looked at; 1 file(s) made; 0 failed.'])
        ->and(WP_CLI::messages('success'))->toBe(['Done.']);
});

it('has nothing to warm when the service that makes the sizes does it itself', function (): void {
    $settings = new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F');

    ($this->command)($settings)->warm([], []);

    expect(WP_CLI::messages('warning'))->toBe(['The "imgproxy" driver makes the sizes itself: there is nothing to warm.'])
        ->and(WP_CLI::messages('log'))->toBe([]);
});

it('stops with the message when the size to warm is not declared', function (): void {
    expect(fn () => ($this->command)()->warm([], ['size' => 'nope']))->toThrow(RuntimeException::class, 'The size "nope" is not declared.');
});

it('only lists the stale files in a dry run, and deletes them with --execute', function (): void {
    $stale = '2026/10/photo-card-600x400-'.str_repeat('a', 12).'.webp';
    $this->disk->storage->write('2026/10/photo.jpg', 'x');
    $this->disk->storage->write($stale, 'x');

    ($this->command)()->prune([], []);

    expect(WP_CLI::messages('log'))->toBe(['Would delete '.$stale, 'This was a dry run. Add --execute to apply it.'])
        ->and($this->disk->storage->exists($stale))->toBeTrue();

    WP_CLI::reset();
    ($this->command)()->prune([], ['execute' => true]);

    expect(WP_CLI::messages('log'))->toBe(['Deleted '.$stale])
        ->and($this->disk->storage->exists($stale))->toBeFalse();
});

it('shows the Bunny classes it would create, without writing them', function (): void {
    ($this->command)($this->bunny)->export([], []);

    expect(WP_CLI::messages('line'))->toHaveCount(1)
        ->and(WP_CLI::messages('line')[0])->toStartWith('+ card ')
        ->and(WP_CLI::messages('log'))->toBe(['This was a dry run. Add --execute to write the classes.'])
        ->and($this->pullZone->saved)->toBe([]);
});

it('writes the Bunny classes with --execute and says how many', function (): void {
    ($this->command)($this->bunny)->export([], ['execute' => true]);

    expect($this->pullZone->saved)->toHaveCount(1)
        ->and(WP_CLI::messages('success'))->toBe(['1 class(es) created, 0 updated.']);
});

it('prints the imgproxy configuration, with the warnings once each', function (): void {
    $settings = new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F');

    ($this->command)($settings)->export([], ['driver' => 'imgproxy']);

    expect(WP_CLI::messages('line')[0])->toStartWith('IMGPROXY_PRESETS=')
        ->and(implode("\n", WP_CLI::messages('line')))->not->toContain('736563726574')
        ->and(WP_CLI::messages('log'))->toBe([]);
});

it('stops with the message when there is nothing to export for the driver', function (): void {
    expect(fn () => ($this->command)()->export([], []))->toThrow(RuntimeException::class, 'The pollora driver makes the sizes itself: there is nothing to export.');
});
