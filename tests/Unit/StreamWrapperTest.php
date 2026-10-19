<?php

declare(strict_types=1);

use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\StreamWrapper;
use Pollora\Media\Tests\Fixtures\CountingStorage;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

/*
|--------------------------------------------------------------------------
| WordPress's own file functions, on pollora://, against a real disk
|--------------------------------------------------------------------------
|
| The wrapper is what lets WordPress, Gutenberg, ACF, WooCommerce and every
| import write to any disk without knowing it. Each test uses the functions
| WordPress calls, and reads the result back from the disk.
*/

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    StreamWrapper::register($this->disk->storage, new MediaConfig('https://media.test'));
});

afterEach(function (): void {
    StreamWrapper::unregister();
});

it('writes a file to the disk when the stream is closed, and reads it back', function (): void {
    expect(file_put_contents('pollora://2026/10/photo.jpg', 'bytes'))->toBe(5)
        ->and(file_get_contents($this->disk->root.'/2026/10/photo.jpg'))->toBe('bytes')
        ->and(file_get_contents('pollora://2026/10/photo.jpg'))->toBe('bytes')
        ->and(filesize('pollora://2026/10/photo.jpg'))->toBe(5)
        ->and(file_exists('pollora://2026/10/photo.jpg'))->toBeTrue()
        ->and(is_file('pollora://2026/10/photo.jpg'))->toBeTrue();
});

it('reads and writes with streams like fopen, fread, fwrite, fseek and fgets', function (): void {
    $handle = fopen('pollora://notes.txt', 'w');
    fwrite($handle, "one\ntwo\nthree\n");
    fclose($handle);

    $handle = fopen('pollora://notes.txt', 'r');
    expect(fgets($handle))->toBe("one\n");
    fseek($handle, 4);
    expect(fread($handle, 3))->toBe('two')
        ->and(ftell($handle))->toBe(7)
        ->and(feof($handle))->toBeFalse();
    fseek($handle, 0, SEEK_END);
    fread($handle, 1);
    expect(feof($handle))->toBeTrue();
    fclose($handle);
});

it('appends to an existing file', function (): void {
    file_put_contents('pollora://log.txt', 'a');
    file_put_contents('pollora://log.txt', 'b', FILE_APPEND);

    expect(file_get_contents($this->disk->root.'/log.txt'))->toBe('ab');
});

it('refuses to create a file that exists with mode x, and to read one that does not', function (): void {
    file_put_contents('pollora://there.txt', '1');

    $quietly = static function (callable $call): mixed {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    };

    expect($quietly(static fn () => fopen('pollora://there.txt', 'x')))->toBeFalse()
        ->and($quietly(static fn () => fopen('pollora://nowhere.txt', 'r')))->toBeFalse()
        ->and($quietly(static fn () => file_get_contents('pollora://nowhere.txt')))->toBeFalse()
        ->and(file_exists('pollora://nowhere.txt'))->toBeFalse();
});

it('copies a local file in, a file out, and between two paths', function (): void {
    $local = tempnam(sys_get_temp_dir(), 'pm');
    file_put_contents($local, 'local bytes');

    expect(copy($local, 'pollora://in/local.txt'))->toBeTrue()
        ->and(file_get_contents($this->disk->root.'/in/local.txt'))->toBe('local bytes')
        ->and(copy('pollora://in/local.txt', 'pollora://in/copy.txt'))->toBeTrue()
        ->and(file_get_contents($this->disk->root.'/in/copy.txt'))->toBe('local bytes');

    $out = $local.'.out';
    expect(copy('pollora://in/copy.txt', $out))->toBeTrue()
        ->and(file_get_contents($out))->toBe('local bytes');

    unlink($local);
    unlink($out);
});

it('renames inside the wrapper, and deletes', function (): void {
    file_put_contents('pollora://a.txt', 'x');

    expect(rename('pollora://a.txt', 'pollora://dir/b.txt'))->toBeTrue()
        ->and(file_exists($this->disk->root.'/a.txt'))->toBeFalse()
        ->and(file_get_contents($this->disk->root.'/dir/b.txt'))->toBe('x')
        ->and(unlink('pollora://dir/b.txt'))->toBeTrue()
        ->and(file_exists($this->disk->root.'/dir/b.txt'))->toBeFalse()
        ->and(file_exists('pollora://dir/b.txt'))->toBeFalse();
});

it('answers for directories without asking the disk, so a page render costs no call', function (): void {
    expect(is_dir('pollora://2026/10'))->toBeTrue()
        ->and(file_exists('pollora://2026/10'))->toBeTrue()
        ->and(mkdir('pollora://2026/11', 0755, true))->toBeTrue()
        ->and(is_dir('pollora://'))->toBeTrue()
        ->and(is_file('pollora://2026/10'))->toBeFalse()
        ->and(is_writable('pollora://2026/10'))->toBeTrue();
});

it('lists a directory with scandir and opendir, and removes it', function (): void {
    file_put_contents('pollora://2026/10/a.jpg', '1');
    file_put_contents('pollora://2026/10/b.jpg', '2');
    file_put_contents('pollora://2026/10/sub/c.jpg', '3');

    $names = scandir('pollora://2026/10');
    sort($names);
    expect($names)->toBe(['.', '..', 'a.jpg', 'b.jpg', 'sub']);

    $handle = opendir('pollora://2026/10');
    $seen = [];
    while (($entry = readdir($handle)) !== false) {
        $seen[] = $entry;
    }
    closedir($handle);
    expect(array_diff($seen, ['.', '..']))->toHaveCount(3)
        ->and(rmdir('pollora://2026/10'))->toBeTrue()
        ->and(file_exists($this->disk->root.'/2026/10/a.jpg'))->toBeFalse();
});

it('tolerates what WordPress does after a write: chmod and touch', function (): void {
    file_put_contents('pollora://f.txt', 'x');

    expect(@chmod('pollora://f.txt', 0644))->toBeTrue()
        ->and(@touch('pollora://f.txt'))->toBeTrue();
});

it('gives the same stat for a file as the disk knows', function (): void {
    file_put_contents('pollora://s.txt', '12345');
    $stat = stat('pollora://s.txt');

    expect($stat['size'])->toBe(5)
        ->and($stat['mode'] & 0170000)->toBe(0100000)
        ->and(abs($stat['mtime'] - time()))->toBeLessThan(5)
        ->and(filemtime('pollora://s.txt'))->toBe($stat['mtime']);
});

it('does not ask the disk twice about the same path in a request, and forgets after a write', function (): void {
    file_put_contents('pollora://c.txt', '1');
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    file_exists('pollora://c.txt');
    file_exists('pollora://c.txt');
    filesize('pollora://c.txt');
    expect($counting->existsCalls)->toBe(1);

    file_put_contents('pollora://c.txt', '22');
    clearstatcache();
    expect(filesize('pollora://c.txt'))->toBe(2);
});

it('refuses a path that climbs out of the library', function (): void {
    set_error_handler(static fn (): bool => true);
    $written = file_put_contents('pollora://../escape.txt', 'x');
    restore_error_handler();

    expect($written)->toBeFalse()
        ->and(file_exists(dirname($this->disk->root).'/escape.txt'))->toBeFalse();
});

it('writes with the cache control of the configuration', function (): void {
    $recording = new CountingStorage($this->disk->storage);
    StreamWrapper::register($recording, new MediaConfig('https://media.test', [], 'public, max-age=60'));

    file_put_contents('pollora://h.txt', 'x');

    expect($recording->lastWriteOptions)->toBe(['CacheControl' => 'public, max-age=60']);
});

it('is registered once under the pollora scheme, and unregisters', function (): void {
    expect(in_array('pollora', stream_get_wrappers(), true))->toBeTrue();

    StreamWrapper::unregister();

    expect(in_array('pollora', stream_get_wrappers(), true))->toBeFalse();

    StreamWrapper::register($this->disk->storage, new MediaConfig('https://media.test'));
    StreamWrapper::register($this->disk->storage, new MediaConfig('https://media.test'));

    expect(in_array('pollora', stream_get_wrappers(), true))->toBeTrue();
});
