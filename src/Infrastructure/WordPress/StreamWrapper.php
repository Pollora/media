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

    /** @var resource|null Set by PHP */
    public $context;

    private static ?MediaStorage $storage = null;

    private static ?MediaConfig $config = null;

    /** @var array<string, array{size: int, mtime: int}|false> What the disk said about a file, for this request */
    private static array $stats = [];

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
        self::$storage = $storage;
        self::$config = $config;
        self::$stats = [];

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
    }

    /**
     * The path of a `pollora://` URL on the disk: relative to the library, never outside it.
     */
    public static function keyOf(string $url): ?string
    {
        $key = trim(substr($url, strlen(self::SCHEME.'://')), '/');

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
            $exists = self::$storage->exists($key);

            if ($kind === 'x' && $exists) {
                return $this->fail();
            }

            if ($kind === 'r' && ! $exists) {
                return $this->fail();
            }

            if (($kind === 'r' || $kind === 'a' || $kind === 'c') && $exists) {
                stream_copy_to_stream(self::$storage->readStream($key), $this->buffer);
                rewind($this->buffer);
            }

            if ($kind === 'a') {
                fseek($this->buffer, 0, SEEK_END);
            }
        } catch (Throwable $throwable) {
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
            unset(self::$stats[$this->key]);
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

        try {
            if (! self::$storage->exists($key)) {
                return false;
            }

            self::$storage->delete($key);
            unset(self::$stats[$key]);

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
            unset(self::$stats[$source], self::$stats[$destination]);

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
