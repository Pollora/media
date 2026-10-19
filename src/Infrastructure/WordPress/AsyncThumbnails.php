<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Hook\Domain\Contract\Action;

/**
 * Hands the image sizes the media library does not need at once to a
 * deferred job, so an upload returns with the original, the thumbnail and the
 * medium size. Off by default: the block editor sometimes waits for a size
 * right after the upload.
 *
 * Until the job ran, the sizes missing from the metadata cost nothing: WordPress serves
 * the nearest size it has, the original at worst. The job is an asynchronous action, so
 * it runs on Action Scheduler or WP-Cron, or on the queue a framework gives it.
 */
final class AsyncThumbnails
{
    public const string HOOK = 'pollora/media/generate_subsizes';

    /** @var list<string> Sizes the media library and the block editor need right after an upload */
    private const array EAGER = ['thumbnail', 'medium'];

    private static bool $everything = false;

    /**
     * @param  Action  $action  The hook service of the project: the framework's, or the package's own without it
     */
    public function __construct(private readonly ?Action $action = null) {}

    public function register(): void
    {
        \add_filter('intermediate_image_sizes_advanced', [$this, 'keepEagerSizes'], 99, 1);
        \add_filter('wp_generate_attachment_metadata', [$this, 'queueTheRest'], 99, 3);

        if ($this->action instanceof Action) {
            $this->action->add(self::HOOK, [self::class, 'generate']);
            $this->action->async()->unique()->tries(3);
        }
    }

    /**
     * @param  array<string, mixed>  $sizes
     * @return array<string, mixed>
     */
    public function keepEagerSizes(array $sizes): array
    {
        return self::$everything ? $sizes : array_intersect_key($sizes, array_flip(self::EAGER));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function queueTheRest(array $metadata, int $attachmentId, string $context = 'create'): array
    {
        if ($context === 'create' && \wp_get_missing_image_subsizes($attachmentId) !== []) {
            \do_action(self::HOOK, $attachmentId);
        }

        return $metadata;
    }

    /**
     * The deferred job: the sizes WordPress knows and the attachment lacks.
     */
    public static function generate(int $attachmentId): void
    {
        if (! function_exists('wp_update_image_subsizes') && defined('ABSPATH')) {
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        self::$everything = true;

        try {
            \wp_update_image_subsizes($attachmentId);
        } finally {
            self::$everything = false;
        }
    }

    public static function generatingEverything(bool $everything): void
    {
        self::$everything = $everything;
    }

    public static function isGeneratingEverything(): bool
    {
        return self::$everything;
    }
}
