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

it('does not read a file back from the disk in the request that wrote it, nor ask its size', function (): void {
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    file_put_contents('pollora://2026/10/photo.jpg', 'original bytes');

    expect(file_get_contents('pollora://2026/10/photo.jpg'))->toBe('original bytes')
        ->and(file_get_contents('pollora://2026/10/photo.jpg'))->toBe('original bytes')
        ->and(filesize('pollora://2026/10/photo.jpg'))->toBe(14)
        ->and(file_exists('pollora://2026/10/photo.jpg'))->toBeTrue()
        ->and($counting->readCalls)->toBe(0)
        ->and($counting->statCalls)->toBe(0)
        ->and($counting->existsCalls)->toBe(0);
});

it('reads a file the disk already had once, however many times the request reads it', function (): void {
    $this->disk->storage->write('2026/10/old.jpg', 'old bytes');
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    foreach (range(1, 3) as $ignored) {
        expect(file_get_contents('pollora://2026/10/old.jpg'))->toBe('old bytes');
    }

    expect($counting->readCalls)->toBe(1);
});

it('serves the new bytes after a file is rewritten, deleted or renamed', function (): void {
    file_put_contents('pollora://a.jpg', 'one');
    file_get_contents('pollora://a.jpg');
    file_put_contents('pollora://a.jpg', 'two');

    expect(file_get_contents('pollora://a.jpg'))->toBe('two');

    rename('pollora://a.jpg', 'pollora://b.jpg');
    expect(file_get_contents('pollora://b.jpg'))->toBe('two')
        ->and(file_exists('pollora://a.jpg'))->toBeFalse();

    unlink('pollora://b.jpg');
    expect(file_exists('pollora://b.jpg'))->toBeFalse();
});

it('does not keep a file bigger than the limit on the local disk, and still reads it', function (): void {
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));
    StreamWrapper::limitLocalCopies(10);

    file_put_contents('pollora://big.bin', str_repeat('x', 100));

    expect(file_get_contents('pollora://big.bin'))->toBe(str_repeat('x', 100))
        ->and($counting->readCalls)->toBe(1);

    StreamWrapper::limitLocalCopies(StreamWrapper::DEFAULT_LOCAL_COPY_LIMIT);
});

it('removes its local copies when it unregisters', function (): void {
    file_put_contents('pollora://tmp-check.jpg', 'x');
    $before = glob(sys_get_temp_dir().'/pollora-media-copy-*');

    StreamWrapper::unregister();

    expect($before)->not->toBe([])
        ->and(glob(sys_get_temp_dir().'/pollora-media-copy-*'))->toBe([]);
});

it('deletes without asking the disk first, and says false for a file it found missing', function (): void {
    $this->disk->storage->write('gone.jpg', 'x');
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    expect(unlink('pollora://gone.jpg'))->toBeTrue()
        ->and($counting->existsCalls)->toBe(0)
        ->and(file_exists('pollora://gone.jpg'))->toBeFalse()
        ->and(@unlink('pollora://gone.jpg'))->toBeFalse();
});

it('fails to open a file whose local copy has gone, rather than reading it as empty', function (): void {
    $before = glob(sys_get_temp_dir().'/pollora-media-copy-*') ?: [];
    file_put_contents('pollora://gone/copy.txt', 'bytes');
    $copies = array_diff(glob(sys_get_temp_dir().'/pollora-media-copy-*') ?: [], $before);

    expect($copies)->toHaveCount(1);

    array_map(unlink(...), $copies);

    // PHP warns of the failed fopen(): the result is what matters here
    set_error_handler(static fn (): bool => true);

    try {
        expect(file_get_contents('pollora://gone/copy.txt'))->toBeFalse();
    } finally {
        restore_error_handler();
    }
});

it('takes pollora://uploads as the root of the library, so WordPress can trim the slashes of its base', function (): void {
    file_put_contents('pollora://uploads/2026/10/a.jpg', 'x');

    expect(file_get_contents($this->disk->root.'/2026/10/a.jpg'))->toBe('x')
        ->and(file_exists('pollora://uploads/2026/10/a.jpg'))->toBeTrue()
        ->and(file_exists('pollora://2026/10/a.jpg'))->toBeTrue()
        ->and(StreamWrapper::keyOf('pollora://uploads'))->toBe('')
        ->and(StreamWrapper::keyOf('pollora://uploads/sites/3/b.jpg'))->toBe('sites/3/b.jpg')
        ->and(StreamWrapper::keyOf('pollora://uploadsX/b.jpg'))->toBe('uploadsX/b.jpg')
        ->and(is_dir('pollora://uploads'))->toBeTrue();
});

it('keeps a write that reached the disk when there is no temporary directory for its local copy', function (): void {
    // The temporary directory of PHP can only be set when it starts: another process does the write
    $script = sprintf(<<<'PHP'
        require %s;
        $disk = new Pollora\Media\Infrastructure\Flysystem\FlysystemMediaStorage(new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter(%s)));
        Pollora\Media\Infrastructure\WordPress\StreamWrapper::register($disk, new Pollora\Media\Domain\Models\MediaConfig('https://media.test'));
        echo file_put_contents('pollora://2026/10/photo.jpg', 'bytes');
        PHP, var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true), var_export($this->disk->root, true));

    // Errors only: the oldest dependencies allowed print deprecations on this PHP
    exec(sprintf('%s -d error_reporting=%d -d sys_temp_dir=%s -r %s 2>&1', escapeshellarg(PHP_BINARY), E_ERROR, escapeshellarg($this->disk->root.'/missing'), escapeshellarg($script)), $output, $status);

    expect($status)->toBe(0, implode("\n", $output))
        ->and(implode('', $output))->toBe('5')
        ->and(file_get_contents($this->disk->root.'/2026/10/photo.jpg'))->toBe('bytes');
});

/** The local copies that hold these bytes: other tests, run in parallel, keep copies of their own in the same directory. */
function localCopiesOf(string $bytes): array
{
    return array_values(array_filter(
        glob(sys_get_temp_dir().'/pollora-media-copy-*') ?: [],
        static fn (string $copy): bool => @file_get_contents($copy) === $bytes,
    ));
}

function withoutWarnings(Closure $call): mixed
{
    set_error_handler(static fn (): bool => true);

    try {
        return $call();
    } finally {
        restore_error_handler();
    }
}

it('refuses every operation on a path that climbs out of the library, and touches nothing', function (): void {
    $this->disk->storage->write('inside.txt', 'inside');
    $outside = dirname($this->disk->root).'/pollora-outside-'.bin2hex(random_bytes(4)).'.txt';
    file_put_contents($outside, 'outside');
    $climb = 'pollora://../'.basename($outside);

    try {
        withoutWarnings(function () use ($climb): void {
            expect(file_exists($climb))->toBeFalse()
                ->and(unlink($climb))->toBeFalse()
                ->and(rename($climb, 'pollora://stolen.txt'))->toBeFalse()
                ->and(rename('pollora://inside.txt', $climb))->toBeFalse()
                ->and(mkdir('pollora://../folder'))->toBeFalse()
                ->and(rmdir('pollora://..'))->toBeFalse()
                ->and(opendir('pollora://..'))->toBeFalse();
        });

        expect(file_get_contents($outside))->toBe('outside')
            ->and(file_get_contents($this->disk->root.'/inside.txt'))->toBe('inside')
            ->and(file_exists($this->disk->root.'/stolen.txt'))->toBeFalse();
    } finally {
        unlink($outside);
    }
});

it('answers false, without an error, when no disk is configured', function (): void {
    StreamWrapper::unregister();
    $wrapper = new StreamWrapper;
    $opened = null;

    expect($wrapper->stream_open('pollora://a.txt', 'w', 0, $opened))->toBeFalse()
        ->and($wrapper->url_stat('pollora://a.txt', 0))->toBeFalse()
        ->and($wrapper->unlink('pollora://a.txt'))->toBeFalse()
        ->and($wrapper->rename('pollora://a.txt', 'pollora://b.txt'))->toBeFalse()
        ->and($wrapper->rmdir('pollora://folder', 0))->toBeFalse()
        ->and($wrapper->dir_opendir('pollora://folder', 0))->toBeFalse();
});

it('keeps one local copy of a file however often it is written, so uploads do not pile up in the temporary directory', function (): void {
    $marker = 'one-copy-'.bin2hex(random_bytes(8));

    file_put_contents('pollora://private/contract.pdf', $marker);
    file_put_contents('pollora://private/contract.pdf', $marker);
    file_put_contents('pollora://private/contract.pdf', $marker);

    expect(localCopiesOf($marker))->toHaveCount(1);
});

it('removes the local copy of a file that is deleted, renamed, or removed with its folder', function (string $operation): void {
    $marker = 'gone-copy-'.bin2hex(random_bytes(8));
    file_put_contents('pollora://folder/secret.txt', $marker);

    match ($operation) {
        'unlink' => unlink('pollora://folder/secret.txt'),
        'rename' => rename('pollora://folder/secret.txt', 'pollora://elsewhere.txt'),
        'rmdir' => rmdir('pollora://folder'),
    };

    expect(localCopiesOf($marker))->toBe([])
        ->and(withoutWarnings(static fn () => file_get_contents('pollora://folder/secret.txt')))->toBeFalse();
})->with(['unlink', 'rename', 'rmdir']);

it('forgets what it kept of the previous disk when it is registered again', function (): void {
    $marker = 'first-disk-'.bin2hex(random_bytes(8));
    file_put_contents('pollora://same.txt', $marker);

    $second = new TemporaryStorage;
    $second->storage->write('same.txt', 'second disk');
    StreamWrapper::register($second->storage, new MediaConfig('https://media.test'));

    expect(file_get_contents('pollora://same.txt'))->toBe('second disk')
        ->and(localCopiesOf($marker))->toBe([]);
});

it('gives the bytes of the source once a file is renamed over one it had already read', function (): void {
    file_put_contents('pollora://old.txt', 'old bytes');
    file_put_contents('pollora://new.txt', 'new, longer bytes');
    file_get_contents('pollora://old.txt');

    rename('pollora://new.txt', 'pollora://old.txt');
    clearstatcache();

    expect(file_get_contents('pollora://old.txt'))->toBe('new, longer bytes')
        ->and(filesize('pollora://old.txt'))->toBe(17);
});

it('remembers the size and date of a file it has read, and keeps the date the disk gave', function (): void {
    $this->disk->storage->write('read.txt', '123456');
    $this->disk->storage->write('dated.txt', '12');
    touch($this->disk->root.'/dated.txt', 1_000_000_000);
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    file_get_contents('pollora://read.txt');
    $stat = stat('pollora://read.txt');

    expect($stat['size'])->toBe(6)
        ->and(abs($stat['mtime'] - time()))->toBeLessThan(5)
        ->and($counting->statCalls)->toBe(0);

    // The date the disk reports, whatever the filesystem made of the one asked
    $dated = $this->disk->storage->lastModified('dated.txt');
    clearstatcache();
    expect($dated)->toBeLessThan(time() - 3600)
        ->and(filemtime('pollora://dated.txt'))->toBe($dated);
    file_get_contents('pollora://dated.txt');
    clearstatcache();
    expect(filemtime('pollora://dated.txt'))->toBe($dated);
});

it('opens a file it already knows without asking the disk whether it is there', function (): void {
    $this->disk->storage->write('known.txt', 'x');
    $counting = new CountingStorage($this->disk->storage);
    StreamWrapper::register($counting, new MediaConfig('https://media.test'));

    file_exists('pollora://known.txt');
    fclose(fopen('pollora://known.txt', 'r'));

    expect($counting->existsCalls)->toBe(1);
});

it('creates a file that does not exist with mode x', function (): void {
    $handle = fopen('pollora://fresh.txt', 'x');
    fwrite($handle, 'new');
    fclose($handle);

    expect(file_get_contents($this->disk->root.'/fresh.txt'))->toBe('new');
});

it('reports a seek, a stat of an open stream, and the permissions WordPress checks before it writes', function (): void {
    file_put_contents('pollora://dir/f.txt', 'abcdef');
    $handle = fopen('pollora://dir/f.txt', 'r');

    expect(fseek($handle, 2))->toBe(0)
        ->and(fseek($handle, -20))->toBe(-1)
        ->and(fstat($handle)['size'])->toBe(6)
        ->and(fstat($handle)['mode'])->toBe(0100644);
    fclose($handle);

    clearstatcache();
    expect(fileperms('pollora://dir'))->toBe(040755)
        ->and(fileperms('pollora://dir/f.txt'))->toBe(0100644)
        ->and(filesize('pollora://dir'))->toBe(0)
        ->and(fileowner('pollora://dir'))->toBe(getmyuid())
        ->and(filegroup('pollora://dir'))->toBe(getmygid());
});

it('removes its local copies when the process ends', function (): void {
    $marker = 'at-shutdown-'.bin2hex(random_bytes(8));
    $script = sprintf(
        'require %s; $d = new Pollora\Media\Tests\Fixtures\TemporaryStorage; Pollora\Media\Infrastructure\WordPress\StreamWrapper::register($d->storage, new Pollora\Media\Domain\Models\MediaConfig("https://media.test")); echo file_put_contents("pollora://a.txt", %s);',
        var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
        var_export($marker, true),
    );

    exec(sprintf('%s -d error_reporting=%d -r %s 2>&1', escapeshellarg(PHP_BINARY), E_ERROR, escapeshellarg($script)), $output, $status);

    expect($status)->toBe(0, implode("\n", $output))
        ->and(implode('', $output))->toBe((string) strlen($marker))
        ->and(localCopiesOf($marker))->toBe([]);
});
