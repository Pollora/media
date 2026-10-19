<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\MediaMigrator;
use Pollora\Media\Tests\Fixtures\CountingStorage;
use Pollora\Media\Tests\Fixtures\FailingStorage;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->from = new TemporaryStorage;
    $this->to = new TemporaryStorage;
    foreach (['2026/10/a.jpg' => 'aaa', '2026/10/b.jpg' => 'bbbb', '2025/01/c.pdf' => 'c', 'top.txt' => 't'] as $path => $bytes) {
        $this->from->storage->write($path, $bytes);
    }
    $this->migrator = new MediaMigrator($this->from->storage, $this->to->storage);
});

it('only says what it would copy in a dry run', function (): void {
    $report = $this->migrator->migrate(dryRun: true);

    expect($report->copied)->toBe(['2025/01/c.pdf', '2026/10/a.jpg', '2026/10/b.jpg', 'top.txt'])
        ->and($report->skipped)->toBe([])
        ->and($report->failed)->toBe([])
        ->and($report->dryRun)->toBeTrue()
        ->and($this->to->storage->exists('2026/10/a.jpg'))->toBeFalse();
});

it('copies every file with its path, and leaves the source alone', function (): void {
    $report = $this->migrator->migrate(dryRun: false);

    expect($report->copied)->toHaveCount(4)
        ->and(file_get_contents($this->to->root.'/2026/10/b.jpg'))->toBe('bbbb')
        ->and(file_get_contents($this->to->root.'/top.txt'))->toBe('t')
        ->and($this->from->storage->exists('2026/10/a.jpg'))->toBeTrue();
});

it('skips what is already there with the same size, so it can run again', function (): void {
    $this->to->storage->write('2026/10/a.jpg', 'aaa');
    $this->to->storage->write('2026/10/b.jpg', 'different size');

    $report = $this->migrator->migrate(dryRun: false);

    expect($report->skipped)->toBe(['2026/10/a.jpg'])
        ->and($report->copied)->toContain('2026/10/b.jpg')
        ->and(file_get_contents($this->to->root.'/2026/10/b.jpg'))->toBe('bbbb');
});

it('reports a file it could not copy and goes on', function (): void {
    $storage = new FailingStorage($this->to->storage, '2026/10/b.jpg');
    $report = (new MediaMigrator($this->from->storage, $storage))->migrate(dryRun: false);

    expect($report->failed)->toBe(['2026/10/b.jpg'])
        ->and($report->copied)->toHaveCount(3);
});

it('tells the progress file by file', function (): void {
    $seen = [];
    $this->migrator->migrate(dryRun: true, progress: function (string $path, string $outcome) use (&$seen): void {
        $seen[$path] = $outcome;
    });

    expect($seen)->toHaveCount(4)->and($seen['top.txt'])->toBe('copy');
});

it('sends the cache header with each file it copies', function (): void {
    $target = new CountingStorage($this->to->storage);

    (new MediaMigrator($this->from->storage, $target, cacheControl: 'public, max-age=31536000, immutable'))->migrate(dryRun: false);

    expect($target->writeOptions['2026/10/a.jpg']['CacheControl'])->toBe('public, max-age=31536000, immutable')
        ->and($target->writeOptions['top.txt']['CacheControl'])->toBe('public, max-age=31536000, immutable');
});

it('sends no cache header when none is given', function (): void {
    $target = new CountingStorage($this->to->storage);

    $this->migrator = new MediaMigrator($this->from->storage, $target);
    $this->migrator->migrate(dryRun: false);

    expect($target->writeOptions['top.txt'])->not->toHaveKey('CacheControl');
});

it('keeps a private file private, and a public file public', function (): void {
    $this->from->storage->setVisibility('2026/10/b.jpg', 'private');

    $this->migrator->migrate(dryRun: false);

    expect($this->to->storage->visibility('2026/10/b.jpg'))->toBe('private')
        ->and($this->to->storage->visibility('2026/10/a.jpg'))->toBe('public');
});

it('sends the visibility with the write, not in a second request', function (): void {
    $this->from->storage->setVisibility('top.txt', 'private');
    $target = new CountingStorage($this->to->storage);

    (new MediaMigrator($this->from->storage, $target))->migrate(dryRun: false);

    expect($target->writeOptions['top.txt']['visibility'])->toBe('private')
        ->and($target->writeOptions['2026/10/a.jpg']['visibility'])->toBe('public');
});

it('leaves out the folders it is told to exclude', function (): void {
    $this->from->storage->write('woocommerce_uploads/invoice.pdf', 'secret');
    $this->from->storage->write('wc-logs/fatal-errors.log', 'log');

    $report = $this->migrator->migrate(dryRun: false, exclude: ['woocommerce_uploads/*', 'wc-logs/*']);

    expect($report->excluded)->toBe(['wc-logs/fatal-errors.log', 'woocommerce_uploads/invoice.pdf'])
        ->and($report->copied)->toHaveCount(4)
        ->and($this->to->storage->exists('woocommerce_uploads/invoice.pdf'))->toBeFalse()
        ->and($this->to->storage->exists('wc-logs/fatal-errors.log'))->toBeFalse()
        ->and($this->to->storage->exists('2026/10/a.jpg'))->toBeTrue();
});

it('matches an exclusion at any depth and on a file name', function (): void {
    $this->from->storage->write('2026/10/backup-site.zip', 'zip');
    $this->from->storage->write('plugin/cache/deep/x.tmp', 'tmp');

    $report = $this->migrator->migrate(dryRun: true, exclude: ['*.zip', 'plugin/*']);

    expect($report->excluded)->toBe(['2026/10/backup-site.zip', 'plugin/cache/deep/x.tmp'])
        ->and($report->copied)->not->toContain('2026/10/backup-site.zip');
});

it('does not exclude a folder that only starts with the same letters', function (): void {
    $this->from->storage->write('wc-logs-archive/a.txt', 'a');

    $report = $this->migrator->migrate(dryRun: true, exclude: ['wc-logs/*']);

    expect($report->excluded)->toBe([])
        ->and($report->copied)->toContain('wc-logs-archive/a.txt');
});

it('tells which files it left out, as the progress', function (): void {
    $this->from->storage->write('wc-logs/a.log', 'a');
    $seen = [];

    $this->migrator->migrate(dryRun: true, progress: function (string $path, string $outcome) use (&$seen): void {
        $seen[$path] = $outcome;
    }, exclude: ['wc-logs/*']);

    expect($seen['wc-logs/a.log'])->toBe('exclude')->and($seen['top.txt'])->toBe('copy');
});

it('does not count an excluded file as already there or failed', function (): void {
    $this->from->storage->write('wc-logs/a.log', 'a');
    $this->to->storage->write('wc-logs/a.log', 'a');

    $report = $this->migrator->migrate(dryRun: true, exclude: ['wc-logs/*']);

    expect($report->skipped)->toBe([])->and($report->failed)->toBe([]);
});
