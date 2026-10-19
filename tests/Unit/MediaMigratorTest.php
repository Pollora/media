<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\MediaMigrator;
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
