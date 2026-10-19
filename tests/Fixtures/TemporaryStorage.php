<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Media\Infrastructure\Flysystem\FlysystemMediaStorage;

/**
 * A real Flysystem local adapter in a directory of its own, removed when the test ends.
 */
final class TemporaryStorage
{
    public readonly string $root;

    public readonly FlysystemMediaStorage $storage;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/pollora-media-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->storage = new FlysystemMediaStorage(new Filesystem(new LocalFilesystemAdapter($this->root)));
    }

    public function __destruct()
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
