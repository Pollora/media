<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Hook\Domain\Contract\Action;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\LegacyUrlRewriter;
use Throwable;

/**
 * Plugs the media library into WordPress: the wrapper, and the few hooks of
 * the core that decide where a file goes and what its URL is. None targets a
 * plugin; whatever goes through the media library follows.
 */
final class WordPressMedia
{
    private readonly LegacyUrlRewriter $legacy;

    private readonly AttachmentFiles $files;

    private readonly PrivateMedia $private;

    /** @var array<int, list<string>> Files of the media being deleted, kept until WordPress has deleted it */
    private array $deleting = [];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaConfig $config,
        private readonly ?Action $action = null,
    ) {
        $this->legacy = new LegacyUrlRewriter($config);
        $this->files = new AttachmentFiles;
        $this->private = new PrivateMedia($storage, $config, $this->files);
    }

    public function register(): void
    {
        StreamWrapper::register($this->storage, $this->config, static function (string $path, int $size): void {
            \do_action('pollora/media/stored', $path, $size);
        });

        \add_filter('upload_dir', [$this, 'uploadDir']);
        \add_filter('pre_wp_unique_filename_file_list', [$this, 'uniqueFilenameFileList'], 10, 3);
        \add_filter('wp_resource_hints', [$this, 'resourceHints'], 10, 2);
        \add_action('delete_attachment', [$this, 'deleteAttachment'], 10, 1);
        \add_action('deleted_post', [$this, 'deletedPost'], 10, 2);
        $this->private->register();

        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('pollora media', new MediaCommand(
                $this->storage,
                $this->config,
                rtrim((string) $this->constant('WP_CONTENT_DIR'), '/').'/uploads',
                rtrim((string) $this->constant('WP_CONTENT_URL'), '/').'/uploads',
                $this->private,
                $this->files,
            ));
        }

        (new PrivacyExports($this->storage, $this->config))->register();

        if ($this->config->asyncThumbnails) {
            (new AsyncThumbnails($this->action))->register();
        }

        if ($this->legacy->isActive()) {
            \add_filter('the_content', [$this, 'content'], 9);
            \add_filter('render_block', [$this, 'block'], 10, 2);
        }
    }

    /**
     * The base of the uploads moves to the wrapper and to the public URL; the
     * date folder, and the folder WordPress adds for a site of a network, stay.
     *
     * @param  array<string, mixed>  $dirs
     * @return array<string, mixed>
     */
    public function uploadDir(array $dirs): array
    {
        if (! empty($dirs['error'])) {
            return $dirs;
        }

        $site = preg_match('#/sites/\d+$#', (string) $dirs['basedir'], $match) === 1 ? $match[0] : '';
        $subdir = (string) $dirs['subdir'];

        $dirs['basedir'] = StreamWrapper::ROOT.$site;
        $dirs['baseurl'] = $this->config->url.$site;
        $dirs['path'] = StreamWrapper::ROOT.$site.$subdir;
        $dirs['url'] = $this->config->url.$site.$subdir;

        return $dirs;
    }

    /**
     * WordPress lists the folder of a new file to find the names it must not reuse;
     * on a remote disk that is a listing per upload. The name itself is still checked.
     *
     * @param  array<int, string>|null  $files
     * @return array<int, string>|null
     */
    public function uniqueFilenameFileList(?array $files, string $dir, string $filename): ?array
    {
        if ($files !== null || ! str_starts_with($dir, StreamWrapper::SCHEME.'://')) {
            return $files;
        }

        return [];
    }

    /**
     * Files a plugin keeps next to a media (a WebP twin, a preview) go with it.
     */
    public function deleteAttachment(int $attachmentId): void
    {
        $files = \apply_filters('pollora/media/attachment_files', [], $attachmentId);
        $this->deleting[$attachmentId] = $this->files->for($attachmentId);

        foreach (is_array($files) ? $files : [] as $path) {
            try {
                $path = ltrim((string) $path, '/');

                if ($this->storage->exists($path)) {
                    $this->storage->delete($path);
                }
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * Tells the files of a media that WordPress has deleted, once it has.
     */
    public function deletedPost(int $postId, mixed $post = null): void
    {
        if (isset($this->deleting[$postId])) {
            \do_action('pollora/media/deleted', $postId, $this->deleting[$postId]);
            unset($this->deleting[$postId]);
        }
    }

    public function privateMedia(): PrivateMedia
    {
        return $this->private;
    }

    /**
     * @param  array<int, mixed>  $urls
     * @return array<int, mixed>
     */
    public function resourceHints(array $urls, string $relation): array
    {
        if ($relation !== 'dns-prefetch') {
            return $urls;
        }

        $host = parse_url($this->config->url, PHP_URL_HOST);

        if (! is_string($host) || $host === parse_url(\home_url(), PHP_URL_HOST)) {
            return $urls;
        }

        $hint = '//'.$host;

        return in_array($hint, $urls, true) ? $urls : [...$urls, $hint];
    }

    private function constant(string $name): string
    {
        return defined($name) ? (string) constant($name) : '';
    }

    public function content(string $content): string
    {
        return $this->legacy->rewrite($content);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public function block(string $html, array $block): string
    {
        return $this->legacy->rewrite($html);
    }
}
