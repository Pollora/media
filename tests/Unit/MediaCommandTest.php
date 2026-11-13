<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\AttachmentFiles;
use Pollora\Media\Infrastructure\WordPress\MediaCommand;
use Pollora\Media\Infrastructure\WordPress\PrivateMedia;
use Pollora\Media\Tests\Fixtures\FailingStorage;
use Pollora\Media\Tests\Fixtures\FakeDatabase;
use Pollora\Media\Tests\Fixtures\SigningStorage;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    WP_CLI::reset();
    $this->disk = new TemporaryStorage;
    $this->config = new MediaConfig('https://media.client.fr');
    $this->source = sys_get_temp_dir().'/pollora-media-source-'.bin2hex(random_bytes(4));
    mkdir($this->source.'/2026/10', 0777, true);
    mkdir($this->source.'/wc-logs', 0777, true);
    file_put_contents($this->source.'/2026/10/photo.jpg', 'original');
    file_put_contents($this->source.'/wc-logs/today.log', 'log');
    $this->meta = [7 => ['_wp_attached_file' => '2026/10/photo.jpg']];
    Functions\when('get_post_meta')->alias(fn (int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? '');
    Functions\when('update_post_meta')->justReturn(true);
    Functions\when('delete_post_meta')->justReturn(true);
    Functions\when('wp_get_attachment_metadata')->justReturn(['file' => '2026/10/photo.jpg', 'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg']]]);
    $this->command = fn (?object $storage = null, ?string $from = null): MediaCommand => new MediaCommand(
        $storage ?? new SigningStorage($this->disk->storage),
        $this->config,
        $from ?? $this->source,
        'https://site.test/wp-content/uploads',
        new PrivateMedia($storage ?? new SigningStorage($this->disk->storage), $this->config, new AttachmentFiles),
        new AttachmentFiles,
    );
});

afterEach(function (): void {
    foreach (['/2026/10/photo.jpg', '/wc-logs/today.log'] as $file) {
        @unlink($this->source.$file);
    }
    foreach (['/2026/10', '/2026', '/wc-logs', ''] as $directory) {
        @rmdir($this->source.$directory);
    }
    unset($GLOBALS['wpdb']);
});

it('shows the public URL and says the disk works', function (): void {
    ($this->command)()->status();

    expect(WP_CLI::messages('log'))->toBe(['Public URL: https://media.client.fr'])
        ->and(WP_CLI::messages('success'))->toBe(['The disk takes a file, gives it back and deletes it.']);
});

it('fails the status with the reason when the disk takes no file', function (): void {
    $refusing = new FailingStorage($this->disk->storage, '');
    $storage = new class($this->disk->storage) extends stdClass {};
    $broken = new class($this->disk->storage) implements MediaStorage
    {
        public function __construct(private readonly MediaStorage $inner) {}

        public function exists(string $path): bool
        {
            return $this->inner->exists($path);
        }

        public function readStream(string $path)
        {
            return $this->inner->readStream($path);
        }

        public function write(string $path, mixed $contents, array $options = []): void
        {
            throw new RuntimeException('read-only disk');
        }

        public function delete(string $path): void {}

        public function deleteDirectory(string $path): void {}

        public function copy(string $source, string $destination): void {}

        public function move(string $source, string $destination): void {}

        public function visibility(string $path): string
        {
            return 'public';
        }

        public function setVisibility(string $path, string $visibility): void {}

        public function temporaryUrl(string $path, DateTimeInterface $expiresAt): ?string
        {
            return null;
        }

        public function size(string $path): int
        {
            return 0;
        }

        public function lastModified(string $path): int
        {
            return 0;
        }

        public function list(string $path, bool $deep = false): array
        {
            return [];
        }
    };

    expect(fn () => ($this->command)($broken)->status())->toThrow(RuntimeException::class, 'WP_CLI::error: The disk does not take a file: read-only disk');
});

it('lists the files of a media, and marks the ones the disk does not have', function (): void {
    $this->disk->storage->write('2026/10/photo.jpg', 'x');

    ($this->command)()->files(['7']);

    expect(WP_CLI::messages('log'))->toBe([
        'Media 7 is public.',
        '2026/10/photo.jpg',
        '2026/10/photo-150x150.jpg  (not on the disk)',
    ]);
});

it('says a media is private when it is flagged so', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';

    ($this->command)()->files(['7']);

    expect(WP_CLI::messages('log')[0])->toBe('Media 7 is private.');
});

it('changes the visibility of every file of a media and says so', function (): void {
    $this->disk->storage->write('2026/10/photo.jpg', 'x');
    $this->disk->storage->write('2026/10/photo-150x150.jpg', 'x');

    $storage = new SigningStorage($this->disk->storage);

    ($this->command)($storage)->visibility(['7'], ['private' => true]);

    expect(WP_CLI::messages('log'))->toBe(['2 file(s) made private.'])
        ->and(WP_CLI::messages('success'))->toBe(['Done.'])
        ->and($storage->visibility('2026/10/photo.jpg'))->toBe('private');
});

it('makes a media public by default', function (): void {
    $this->disk->storage->write('2026/10/photo.jpg', 'x');
    $this->disk->storage->write('2026/10/photo-150x150.jpg', 'x');

    ($this->command)()->visibility(['7'], []);

    expect(WP_CLI::messages('log'))->toBe(['2 file(s) made public.']);
});

it('warns of each file whose visibility could not change, and halts', function (): void {
    $this->disk->storage->write('2026/10/photo.jpg', 'x');

    expect(fn () => ($this->command)()->visibility(['7'], ['private' => true]))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1')
        ->and(WP_CLI::messages('warning'))->toBe(['Not changed: 2026/10/photo-150x150.jpg']);
});

it('copies nothing without --execute and says it was a dry run', function (): void {
    ($this->command)()->migrate([], []);

    expect(WP_CLI::messages('log'))->toBe(['Would copy 2 file(s); 0 already there; 0 left out; 0 failed.', 'This was a dry run. Add --execute to apply it.'])
        ->and($this->disk->storage->exists('2026/10/photo.jpg'))->toBeFalse();
});

it('copies the files with --execute, leaving out what --exclude names', function (): void {
    ($this->command)()->migrate([], ['execute' => true, 'exclude' => 'wc-logs/*, ']);

    expect(WP_CLI::messages('log'))->toBe(['Copied 1 file(s); 0 already there; 1 left out; 0 failed.'])
        ->and($this->disk->storage->exists('2026/10/photo.jpg'))->toBeTrue()
        ->and($this->disk->storage->exists('wc-logs/today.log'))->toBeFalse();
});

it('takes the folder to copy from --from', function (): void {
    ($this->command)(null, '/nowhere')->migrate([], ['from' => $this->source.'/2026', 'execute' => true]);

    expect($this->disk->storage->exists('10/photo.jpg'))->toBeTrue();
});

it('stops when the source folder does not exist', function (): void {
    expect(fn () => ($this->command)(null, '/nowhere/at/all')->migrate([], []))->toThrow(RuntimeException::class, 'The source folder does not exist: /nowhere/at/all');
});

it('warns of a file that could not be copied and halts', function (): void {
    $storage = new FailingStorage($this->disk->storage, '2026/10/photo.jpg');

    expect(fn () => ($this->command)($storage)->migrate([], ['execute' => true]))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1')
        ->and(WP_CLI::messages('warning'))->toBe(['Could not copy 2026/10/photo.jpg'])
        ->and(WP_CLI::messages('log')[0])->toContain('1 failed');
});

it('reports the rows the old URL is in with --replace-urls, and writes nothing in a dry run', function (): void {
    $GLOBALS['wpdb'] = new FakeDatabase(['wp_posts' => [1 => ['post_content' => '<img src="https://site.test/wp-content/uploads/a.jpg">']]]);

    ($this->command)()->migrate([], ['replace-urls' => true]);

    expect(WP_CLI::messages('log'))->toContain('Would replace 1 row(s) in posts.post_content')
        ->and($GLOBALS['wpdb']->updates)->toBe([]);
});

it('replaces the old URL with the public URL when it is executed', function (): void {
    Functions\when('wp_cache_flush')->justReturn(true);
    $GLOBALS['wpdb'] = new FakeDatabase(['wp_posts' => [1 => ['post_content' => '<img src="https://old.test/up/a.jpg">']]]);

    ($this->command)()->migrate([], ['execute' => true, 'replace-urls' => true, 'old-url' => 'https://old.test/up/']);

    expect(WP_CLI::messages('log'))->toContain('Replaced 1 row(s) in posts.post_content')
        ->and($GLOBALS['wpdb']->tables['wp_posts'][1]['post_content'])->toBe('<img src="https://media.client.fr/a.jpg">');
});
