<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Throwable;

/**
 * Keeps the personal data export working: WordPress builds its archive with
 * `ZipArchive`, a C library that cannot write to a stream wrapper. The archive
 * is built in a local folder, copied to the disk once it is made, and linked
 * through the public URL of the library; the old ones are deleted from the disk.
 */
final readonly class PrivacyExports
{
    private const string FOLDER = 'wp-personal-data-exports';

    public function __construct(
        private MediaStorage $storage,
        private MediaConfig $config,
    ) {}

    public function register(): void
    {
        \add_filter('wp_privacy_exports_dir', [$this, 'exportsDir']);
        \add_filter('wp_privacy_exports_url', [$this, 'exportsUrl']);
        \add_action('wp_privacy_personal_data_export_file_created', [$this, 'exportCreated'], 10, 1);
        \add_action('wp_privacy_delete_old_export_files', [$this, 'deleteOld']);
    }

    public function exportsDir(string $dir): string
    {
        if (! str_starts_with($dir, StreamWrapper::SCHEME.'://')) {
            return $dir;
        }

        $local = sys_get_temp_dir().'/pollora-media-exports/';

        if (! is_dir($local)) {
            mkdir($local, 0700, true);
        }

        return $local;
    }

    public function exportsUrl(string $url): string
    {
        return $this->config->url.'/'.self::FOLDER.'/';
    }

    public function exportCreated(string $archive): void
    {
        if (! is_file($archive)) {
            return;
        }

        $handle = fopen($archive, 'rb');
        $this->storage->write(self::FOLDER.'/'.basename($archive), $handle);
        fclose($handle);
        unlink($archive);
    }

    public function deleteOld(): void
    {
        $expiration = (int) \apply_filters('wp_privacy_export_expiration', 3 * 24 * 3600);

        try {
            foreach ($this->storage->list(self::FOLDER) as $entry) {
                if ($entry['type'] === 'file' && str_ends_with($entry['path'], '.zip') && $entry['lastModified'] < time() - $expiration) {
                    $this->storage->delete($entry['path']);
                }
            }
        } catch (Throwable) {
            return;
        }
    }
}
