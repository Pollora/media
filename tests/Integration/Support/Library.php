<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Integration\Support;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\ImageSize;
use Pollora\Media\Infrastructure\Flysystem\FlysystemMediaStorage;
use Pollora\Media\Infrastructure\WordPress\WordPressMedia;

/**
 * The media library of the suite: one disk in a temporary folder, the module registered once, for the whole process.
 *
 * The module hangs its filters and a stream wrapper on WordPress, which the process keeps: it is registered the first
 * time a test asks and stays, as it would on a site.
 */
final class Library
{
    public const string URL = 'https://media.example.test/files';

    public const string LEGACY_URL = 'https://old.example.test/uploads';

    private static ?WordPressMedia $media = null;

    private static string $root = '';

    public static function boot(): WordPressMedia
    {
        if (self::$media instanceof WordPressMedia) {
            return self::$media;
        }

        self::$root = sys_get_temp_dir().'/pollora-media-integration-'.bin2hex(random_bytes(4));
        mkdir(self::$root, 0777, true);
        register_shutdown_function(self::remove(...), self::$root);

        // Declared before the module is built: it reads the names of the sizes then.
        ImageSize::add('square')->cover(150, 150)->eager();
        ImageSize::add('card')->cover(600, 400)->webp(75);

        self::$media = new WordPressMedia(
            new FlysystemMediaStorage(new Filesystem(new LocalFilesystemAdapter(self::$root))),
            new MediaConfig(self::URL, [self::LEGACY_URL]),
            new Action,
        );
        self::$media->register();

        return self::$media;
    }

    /**
     * The folder that holds the disk, for a test that looks at the files.
     */
    public static function root(): string
    {
        return self::$root;
    }

    /**
     * Paths, relative to the disk, of the files that exist.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        if (! is_dir(self::$root)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(substr($file->getPathname(), strlen(self::$root)), '/');
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A JPEG a test can upload: a real image, which GD and Imagick open.
     */
    public static function jpeg(int $width = 1200, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 30, 30));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width, $height, (int) imagecolorallocate($image, 30, 30, 200));

        $path = sys_get_temp_dir().'/pollora-media-source-'.bin2hex(random_bytes(4)).'.jpg';
        imagejpeg($image, $path, 85);

        return $path;
    }

    private static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
