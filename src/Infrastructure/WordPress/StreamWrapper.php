<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Throwable;

/**
 * The `pollora://` stream wrapper: WordPress goes on using its file
 * functions (`file_put_contents()`, `copy()`, `unlink()`, `scandir()`…) and the
 * files land on the media disk.
 *
 * Reads and writes go through a temporary buffer: a file is sent to the disk
 * when the stream is closed, in one write. Directories do not exist on an
 * object store, so they are virtual and answer without asking the disk;
 * what is asked about a file is remembered for the rest of the request.
 */
final class StreamWrapper
{
    public const string SCHEME = 'pollora';

    /**
     * The base WordPress is given as the uploads directory. It is not `pollora://` alone: WordPress
     * trims the trailing slashes of a base (`untrailingslashit()`), which would leave `pollora:` and
     * a path on the local disk.
     */
    public const string ROOT = 'pollora://uploads';

    /** Bytes: a file up to this size is kept in a local copy for the rest of the request. */
    public const int DEFAULT_LOCAL_COPY_LIMIT = 16 * 1024 * 1024;

    /** @var resource|null Set by PHP */
    public $context;

    private static ?MediaStorage $storage = null;

    private static ?MediaConfig $config = null;

    /** @var array<string, array{size: int, mtime: int}|false> What the disk said about a file, for this request */
    private static array $stats = [];

    /** @var array<string, string> Local copies of the files written or read in this request, by key */
    private static array $copies = [];

    private static int $copyLimit = self::DEFAULT_LOCAL_COPY_LIMIT;

    private static bool $shutdownRegistered = false;

    /** @var resource|null */
    private $buffer;

    private string $key = '';

    private bool $writable = false;

    private bool $dirty = false;

    /** @var list<string> */
    private array $entries = [];

    private int $position = 0;

    public static function register(MediaStorage $storage, MediaConfig $config): void
    {
        self::forgetCopies();
        self::$storage = $storage;
        self::$config = $config;
        self::$stats = [];

        if (! self::$shutdownRegistered) {
            register_shutdown_function(self::forgetCopies(...));
            self::$shutdownRegistered = true;
        }

        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        self::$storage = null;
        self::$config = null;
        self::$stats = [];
        self::forgetCopies();
    }

    /**
     * The biggest file kept in a local copy. A file written or read in a request is read again in
     * the same request by WordPress (image size, EXIF, editor): the copy saves the trips to the disk.
     */
    public static function limitLocalCopies(int $bytes): void
    {
        self::$copyLimit = $bytes;
    }

    public static function forgetCopies(): void
    {
        foreach (self::$copies as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$copies = [];
    }

    /**
     * The path of a `pollora://` URL on the disk: relative to the library, never outside it.
     */
    public static function keyOf(string $url): ?string
    {
        $key = trim(substr($url, strlen(self::SCHEME.'://')), '/');

        if ($key === 'uploads' || str_starts_with($key, 'uploads/')) {
            $key = ltrim(substr($key, strlen('uploads')), '/');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        return preg_replace('#/{2,}#', '/', $key);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $key = self::keyOf($path);

        if ($key === null || $key === '' || self::$storage === null) {
            return $this->fail();
        }

        $this->key = $key;
        $this->buffer = fopen('php://temp', 'w+b');
        $kind = $mode[0] ?? 'r';
        $this->writable = $kind !== 'r' || str_contains($mode, '+');

        try {
            $copy = self::$copies[$key] ?? null;
            $exists = $kind !== 'w' && $this->known($key, $copy);

            if ($kind === 'x' && $exists) {
                return $this->fail();
            }

            if ($kind === 'r' && ! $exists) {
                return $this->fail();
            }

            if (($kind === 'r' || $kind === 'a' || $kind === 'c') && $exists) {
                $this->load($key, $copy);
            }

            if ($kind === 'a') {
                fseek($this->buffer, 0, SEEK_END);
            }
        } catch (Throwable) {
            return $this->fail();
        }

        $this->dirty = $kind === 'w' || $kind === 'x';

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->buffer, $count);
    }

    public function stream_write(string $data): int
    {
        if (! $this->writable) {
            return 0;
        }

        $this->dirty = true;

        return (int) fwrite($this->buffer, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->buffer);
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->buffer);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->buffer, $offset, $whence) === 0;
    }

    public function stream_truncate(int $size): bool
    {
        $this->dirty = true;

        return ftruncate($this->buffer, $size);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        $stat = fstat($this->buffer);

        return $stat === false ? false : $this->statArray($stat['size'], time(), false);
    }

    public function stream_close(): void
    {
        if ($this->writable && $this->dirty && self::$storage !== null && is_resource($this->buffer)) {
            rewind($this->buffer);
            self::$storage->write($this->key, $this->buffer, ['CacheControl' => self::$config?->cacheControl]);

            $size = (int) (fstat($this->buffer)['size'] ?? 0);
            self::$stats[$this->key] = ['size' => $size, 'mtime' => time()];
            $this->keepCopy($this->key, $size);
        }

        if (is_resource($this->buffer)) {
            fclose($this->buffer);
        }
    }

    /**
     * `touch()`, `chmod()`, `chown()`: a disk has neither modes nor owners; WordPress calls them after a write.
     */
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $key = self::keyOf($path);

        if ($key === null || self::$storage === null) {
            return false;
        }

        if ($key === '' || ! $this->looksLikeAFile($key)) {
            return $this->statArray(0, time(), true);
        }

        if (! array_key_exists($key, self::$stats)) {
            try {
                self::$stats[$key] = self::$storage->exists($key)
                    ? ['size' => self::$storage->size($key), 'mtime' => self::$storage->lastModified($key)]
                    : false;
            } catch (Throwable) {
                self::$stats[$key] = false;
            }
        }

        $stat = self::$stats[$key];

        return $stat === false ? false : $this->statArray($stat['size'], $stat['mtime'], false);
    }

    public function unlink(string $path): bool
    {
        $key = self::keyOf($path);

        if ($key === null || self::$storage === null) {
            return false;
        }

        // A file this request found missing is not asked about again; deleting is idempotent on a disk.
        if ((self::$stats[$key] ?? null) === false) {
            return false;
        }

        try {
            self::$storage->delete($key);
            self::forget($key);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function rename(string $from, string $to): bool
    {
        $source = self::keyOf($from);
        $destination = self::keyOf($to);

        if ($source === null || $destination === null || self::$storage === null) {
            return false;
        }

        try {
            self::$storage->move($source, $destination);
            self::forget($source);
            self::forget($destination);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::keyOf($path) !== null;
    }

    public function rmdir(string $path, int $options): bool
    {
        $key = self::keyOf($path);

        if ($key === null || $key === '' || self::$storage === null) {
            return false;
        }

        try {
            self::$storage->deleteDirectory($key);
            self::$stats = [];
            self::forgetCopies();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $key = self::keyOf($path);

        if ($key === null || self::$storage === null) {
            return false;
        }

        try {
            $names = ['.', '..'];
            foreach (self::$storage->list($key) as $entry) {
                $names[] = basename($entry['path']);
            }
            $this->entries = $names;
            $this->position = 0;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function dir_readdir(): string|false
    {
        return $this->entries[$this->position++] ?? false;
    }

    public function dir_rewinddir(): bool
    {
        $this->position = 0;

        return true;
    }

    public function dir_closedir(): bool
    {
        $this->entries = [];

        return true;
    }

    /**
     * Whether the file is there: what this request already knows, else the disk.
     */
    private function known(string $key, ?string $copy): bool
    {
        if ($copy !== null || is_array(self::$stats[$key] ?? false)) {
            return true;
        }

        return self::$storage->exists($key);
    }

    /**
     * Fills the buffer from the local copy when there is one, else from the disk, and remembers a file that is not too big.
     */
    private function load(string $key, ?string $copy): void
    {
        $source = $copy !== null ? fopen($copy, 'rb') : self::$storage->readStream($key);
        stream_copy_to_stream($source, $this->buffer);
        fclose($source);

        $size = (int) ftell($this->buffer);
        rewind($this->buffer);

        if ($copy === null) {
            self::$stats[$key] ??= ['size' => $size, 'mtime' => time()];
            $this->keepCopy($key, $size);
        }
    }

    /**
     * Keeps what the buffer holds in a temporary file, unless it is bigger than the limit.
     */
    private function keepCopy(string $key, int $size): void
    {
        $previous = self::$copies[$key] ?? null;
        unset(self::$copies[$key]);

        if ($previous !== null && is_file($previous)) {
            unlink($previous);
        }

        if ($size > self::$copyLimit) {
            return;
        }

        $path = sys_get_temp_dir().'/pollora-media-copy-'.bin2hex(random_bytes(8));
        $handle = fopen($path, 'wb');
        $position = (int) ftell($this->buffer);
        rewind($this->buffer);
        stream_copy_to_stream($this->buffer, $handle);
        fseek($this->buffer, $position);
        fclose($handle);

        self::$copies[$key] = $path;
    }

    private static function forget(string $key): void
    {
        unset(self::$stats[$key]);

        if (isset(self::$copies[$key])) {
            if (is_file(self::$copies[$key])) {
                unlink(self::$copies[$key]);
            }

            unset(self::$copies[$key]);
        }
    }

    /**
     * A path with an extension is a file; any other is a directory, which an object store does not have to answer for.
     */
    private function looksLikeAFile(string $key): bool
    {
        return str_contains(basename($key), '.');
    }

    /**
     * @return array<int|string, int>
     */
    private function statArray(int $size, int $mtime, bool $directory): array
    {
        $mode = $directory ? 040755 : 0100644;
        $stat = [0, 0, $mode, 1, (int) getmyuid(), (int) getmygid(), 0, $size, $mtime, $mtime, $mtime, -1, -1];

        return array_merge($stat, array_combine(
            ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'],
            $stat
        ));
    }

    private function fail(): bool
    {
        return false;
    }
}
