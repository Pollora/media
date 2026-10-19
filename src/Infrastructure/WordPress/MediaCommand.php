<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Media\Application\Services\MediaMigrator;
use Pollora\Media\Application\Services\MediaStatus;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\Flysystem\FlysystemMediaStorage;
use WP_CLI;

/**
 * Manage the media library storage.
 *
 * ## EXAMPLES
 *
 *     wp pollora media status
 *     wp pollora media migrate
 *     wp pollora media migrate --execute --replace-urls
 */
final class MediaCommand
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaConfig $config,
        private readonly string $localUploadsPath,
        private readonly string $localUploadsUrl,
        private readonly PrivateMedia $private,
        private readonly AttachmentFiles $files,
    ) {}

    /**
     * Check the media storage: the public URL, and that the disk takes a file.
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $status = (new MediaStatus($this->storage, $this->config))->check();

        WP_CLI::log('Public URL: '.$status['url']);

        $status['writable']
            ? WP_CLI::success('The disk takes a file, gives it back and deletes it.')
            : WP_CLI::error('The disk does not take a file: '.$status['error']);
    }

    /**
     * List the files of a media: the original, each size, and the files a plugin declared with it.
     *
     * ## OPTIONS
     *
     * <id>
     * : The attachment ID.
     *
     * @when after_wp_load
     *
     * @param  array<int, string>  $args
     */
    public function files(array $args): void
    {
        $id = (int) $args[0];
        $visibility = $this->private->isPrivate($id) ? 'private' : 'public';

        WP_CLI::log(sprintf('Media %d is %s.', $id, $visibility));

        foreach ($this->files->for($id) as $file) {
            WP_CLI::log($file.($this->storage->exists($file) ? '' : '  (not on the disk)'));
        }
    }

    /**
     * Make a media private or public: every file of it changes on the disk.
     *
     * A private media is served through a signed URL that expires.
     *
     * ## OPTIONS
     *
     * <id>
     * : The attachment ID.
     *
     * [--private]
     * : Make it private.
     *
     * [--public]
     * : Make it public (the default).
     *
     * @when after_wp_load
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function visibility(array $args, array $assoc): void
    {
        $private = isset($assoc['private']);
        $report = $this->private->setPrivate((int) $args[0], $private);

        WP_CLI::log(sprintf('%d file(s) made %s.', count($report['changed']), $private ? 'private' : 'public'));

        foreach ($report['failed'] as $file) {
            WP_CLI::warning('Not changed: '.$file);
        }

        $report['failed'] === [] ? WP_CLI::success('Done.') : WP_CLI::halt(1);
    }

    /**
     * Copy the media files from a folder to the configured storage.
     *
     * Nothing is written without --execute. The source is never changed.
     *
     * ## OPTIONS
     *
     * [--from=<path>]
     * : Folder to copy from. Default: the uploads folder WordPress uses without this module.
     *
     * [--execute]
     * : Copy the files. Without it, only list what would be copied.
     *
     * [--replace-urls]
     * : After copying, replace the old base URL with the public URL in the database (serialized values included).
     *
     * [--old-url=<url>]
     * : The base URL to replace. Default: the uploads URL WordPress uses without this module.
     *
     * @when after_wp_load
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $execute = isset($assoc['execute']);
        $from = (string) ($assoc['from'] ?? $this->localUploadsPath);

        if (! is_dir($from)) {
            WP_CLI::error('The source folder does not exist: '.$from);
        }

        $report = (new MediaMigrator(new FlysystemMediaStorage(new Filesystem(new LocalFilesystemAdapter($from))), $this->storage))
            ->migrate(! $execute, static function (string $path, string $outcome): void {
                if ($outcome === 'fail') {
                    WP_CLI::warning('Could not copy '.$path);
                }
            });

        WP_CLI::log(sprintf(
            '%s %d file(s); %d already there; %d failed.',
            $execute ? 'Copied' : 'Would copy',
            count($report->copied),
            count($report->skipped),
            count($report->failed),
        ));

        if (isset($assoc['replace-urls'])) {
            $this->replaceUrls((string) ($assoc['old-url'] ?? $this->localUploadsUrl), $execute);
        }

        if (! $execute) {
            WP_CLI::log('This was a dry run. Add --execute to apply it.');
        }

        if ($report->failed !== []) {
            WP_CLI::halt(1);
        }
    }

    private function replaceUrls(string $old, bool $execute): void
    {
        global $wpdb;

        $changed = (new UrlReplacement($wpdb))->replace(rtrim($old, '/'), $this->config->url, ! $execute);

        foreach ($changed as $where => $count) {
            WP_CLI::log(sprintf('%s %d row(s) in %s', $execute ? 'Replaced' : 'Would replace', $count, $where));
        }
    }
}
