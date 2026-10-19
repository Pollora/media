<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use InvalidArgumentException;
use Pollora\Media\Application\Services\SizePruner;
use WP_CLI;

/**
 * Manage the files of the declared image sizes.
 *
 * ## EXAMPLES
 *
 *     wp pollora media thumbs warm --size=card --since=2026-01-01
 *     wp pollora media thumbs prune
 *     wp pollora media thumbs prune --execute
 */
final readonly class ThumbsCommand
{
    public function __construct(
        private SizeWarmer $warmer,
        private SizePruner $pruner,
    ) {}

    /**
     * Make the missing files of the declared sizes for the images already in the library.
     *
     * Adding or changing a size costs nothing until its files are needed: run this before a heavy page goes live.
     *
     * ## OPTIONS
     *
     * [--size=<name>]
     * : Only this declared size (and its extra widths).
     *
     * [--since=<date>]
     * : Only the images uploaded after this date.
     *
     * @when after_wp_load
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function warm(array $args, array $assoc): void
    {
        try {
            $report = $this->warmer->warm($assoc['size'] ?? null, $assoc['since'] ?? null);
        } catch (InvalidArgumentException $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        WP_CLI::log(sprintf('%d image(s) looked at; %d file(s) made; %d failed.', $report['checked'], $report['made'], $report['failed']));

        $report['failed'] === 0 ? WP_CLI::success('Done.') : WP_CLI::halt(1);
    }

    /**
     * Delete the sized files that no declared size stands for any more: a size that was changed or removed.
     *
     * Only files named the way the module names its sizes, next to an original that is still there. Nothing is deleted without --execute.
     *
     * ## OPTIONS
     *
     * [--execute]
     * : Delete the files. Without it, only list them.
     *
     * @when after_wp_load
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $execute = isset($assoc['execute']);

        foreach ($this->pruner->prune(! $execute) as $path) {
            WP_CLI::log(($execute ? 'Deleted ' : 'Would delete ').$path);
        }

        if (! $execute) {
            WP_CLI::log('This was a dry run. Add --execute to apply it.');
        }
    }
}
