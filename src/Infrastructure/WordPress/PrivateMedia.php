<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use DateTimeImmutable;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\MediaConfig;
use Throwable;

/**
 * Private media: a media flagged private is served through a signed URL that
 * expires, and every other media keeps the plain public URL.
 *
 * Nothing is asked of the disk to know: the flag is a post meta, read with the
 * attachment, and asked once per media in a request. The signature is
 * computed for private media only. The module gives no screen to mark a media
 * private: the `pollora/media/is_private` filter, the `visibility` command, or a
 * plugin calling setPrivate().
 */
final class PrivateMedia
{
    /** The post meta that flags a private media. */
    public const string META = '_pollora_media_private';

    /** @var array<int, bool> */
    private array $known = [];

    /** Set while WordPress works out the URL of a size: it builds it from the URL of the media. */
    private bool $downsizing = false;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaConfig $config,
        private readonly AttachmentFiles $files,
    ) {}

    public function register(): void
    {
        \add_filter('image_downsize', [$this, 'startDownsize'], 0, 1);
        \add_filter('wp_get_attachment_url', [$this, 'url'], 10, 2);
        \add_filter('wp_get_attachment_image_src', [$this, 'imageSrc'], 10, 2);
        \add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 10, 5);
        \add_filter('wp_generate_attachment_metadata', [$this, 'generated'], 5, 3);
    }

    public function isPrivate(int $attachmentId): bool
    {
        return $this->known[$attachmentId] ??= (bool) \apply_filters(
            'pollora/media/is_private',
            (string) \get_post_meta($attachmentId, self::META, true) === '1',
            $attachmentId,
        );
    }

    /**
     * WordPress builds the URL of a size by replacing the file name in the URL of the media: that
     * one must still be the plain URL, and the size is signed when `wp_get_attachment_image_src` has it.
     */
    public function startDownsize(mixed $short): mixed
    {
        $this->downsizing = true;

        return $short;
    }

    public function url(string|false $url, int $attachmentId): string|false
    {
        if ($this->downsizing || ! is_string($url)) {
            return $url;
        }

        return $this->isPrivate($attachmentId) ? $this->sign($url, $attachmentId) : $url;
    }

    /**
     * @param  array{0: string, 1: int, 2: int, 3: bool}|false  $image
     * @return array{0: string, 1: int, 2: int, 3: bool}|false
     */
    public function imageSrc(array|false $image, int $attachmentId): array|false
    {
        $this->downsizing = false;

        if (! is_array($image) || ! $this->isPrivate($attachmentId)) {
            return $image;
        }

        $image[0] = $this->sign($image[0], $attachmentId);

        return $image;
    }

    /**
     * @param  array<int, array<string, mixed>>|false  $sources
     * @param  array<string, mixed>  $imageMeta
     * @return array<int, array<string, mixed>>|false
     */
    public function srcset(array|false $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array|false
    {
        if (! is_array($sources) || ! $this->isPrivate($attachmentId)) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            $sources[$width]['url'] = $this->sign((string) $source['url'], $attachmentId);
        }

        return $sources;
    }

    /**
     * Whether the media a file belongs to is private, for a request that has only the path of the original.
     * When WordPress cannot say, the file is treated as private: no public size is made of what may not be public.
     */
    public function isPrivateFile(string $file): bool
    {
        global $wpdb;

        if (! function_exists('get_post_meta') || ! is_object($wpdb)) {
            return true;
        }

        $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $file));

        return $id !== null && $this->isPrivate((int) $id);
    }

    /**
     * A media created private is private on the disk too, from the start.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function generated(array $metadata, int $attachmentId, string $context = 'create'): array
    {
        if ($context === 'create' && $this->isPrivate($attachmentId)) {
            $this->setPrivate($attachmentId, true);
        }

        return $metadata;
    }

    /**
     * Changes the visibility of every file of a media on the disk, then of the media itself.
     *
     * @return array{changed: list<string>, failed: list<string>}
     */
    public function setPrivate(int $attachmentId, bool $private): array
    {
        $changed = [];
        $failed = [];
        foreach ($this->files->for($attachmentId) as $file) {
            try {
                $this->storage->setVisibility($file, $private ? 'private' : 'public');
                $changed[] = $file;
            } catch (Throwable) {
                $failed[] = $file;
            }
        }

        $private ? \update_post_meta($attachmentId, self::META, '1') : \delete_post_meta($attachmentId, self::META);
        $this->known[$attachmentId] = $private;

        \do_action('pollora/media/visibility_changed', $attachmentId, $private);

        return ['changed' => $changed, 'failed' => $failed];
    }

    private function sign(string $url, int $attachmentId): string
    {
        $prefix = $this->config->url.'/';

        if (! str_starts_with($url, $prefix)) {
            return $url;
        }

        $signed = $this->storage->temporaryUrl(substr($url, strlen($prefix)), new DateTimeImmutable($this->config->privateExpiry)) ?? $url;

        return (string) \apply_filters('pollora/media/signed_url', $signed, $attachmentId);
    }
}
