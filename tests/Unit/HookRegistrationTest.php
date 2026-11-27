<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\StreamWrapper;
use Pollora\Media\Infrastructure\WordPress\WordPressMedia;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

/*
 * WordPress gives a callback as many arguments as it asked for when it was added, 1 by default. One that
 * asks for fewer than it declares either fails on a live site (a required parameter) or runs on a default
 * value without a word (`$context = 'create'`).
 */

const MODULE_HOOKS = [
    'upload_dir', 'pre_wp_unique_filename_file_list', 'wp_resource_hints', 'delete_attachment', 'deleted_post',
    'image_downsize', 'wp_get_attachment_url', 'wp_get_attachment_image_src', 'wp_calculate_image_srcset',
    'wp_generate_attachment_metadata', 'wp_update_attachment_metadata', 'intermediate_image_sizes_advanced',
    'update_post_meta', 'delete_post_meta', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'init',
    'parse_request', 'wp_privacy_exports_dir', 'wp_privacy_exports_url', 'wp_privacy_personal_data_export_file_created',
    'wp_privacy_delete_old_export_files', 'the_content', 'render_block',
];

afterEach(function (): void {
    StreamWrapper::unregister();
});

it('asks WordPress for every argument each of its callbacks declares', function (): void {
    Functions\when('did_action')->justReturn(0);
    $added = [];

    // Brain Monkey keeps actions and filters in one list of expectations each: both are listened to
    foreach (MODULE_HOOKS as $hook) {
        $record = function (mixed $callback, int $priority = 10, int $arguments = 1) use (&$added, $hook): void {
            $added[] = [$hook, $callback, $arguments];
        };
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen($record);
        Brain\Monkey\Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen($record);
    }

    $config = new MediaConfig('https://media.client.fr', ['https://old.test/uploads'], asyncThumbnails: true);
    (new WordPressMedia((new TemporaryStorage)->storage, $config))->register();

    expect(array_values(array_unique(array_column($added, 0))))->toEqualCanonicalizing(MODULE_HOOKS);

    foreach ($added as [$hook, $callback, $arguments]) {
        $declared = (new ReflectionFunction(Closure::fromCallable($callback)))->getNumberOfParameters();

        // deleted_post and the like only pass what they have: asking for more is harmless, asking for fewer is not
        expect($arguments)->toBeGreaterThanOrEqual($declared, sprintf('The callback of "%s" declares %d parameter(s) and receives %d.', $hook, $declared, $arguments));
    }
});

it('wires private media, focal points and declared sizes into WordPress when the module registers', function (): void {
    Functions\when('did_action')->justReturn(0);
    $media = new WordPressMedia((new TemporaryStorage)->storage, new MediaConfig('https://media.client.fr'));

    $media->register();

    expect(has_filter('wp_get_attachment_url', [$media->privateMedia(), 'url']))->not->toBeFalse()
        ->and(has_filter('wp_calculate_image_srcset', [$media->privateMedia(), 'srcset']))->not->toBeFalse()
        ->and(has_action('init', [$media->focalPoints(), 'declareMeta']))->not->toBeFalse()
        ->and(has_filter('image_downsize', [$media->declaredSizes(), 'downsize']))->not->toBeFalse()
        ->and(has_action('deleted_post', [$media, 'deletedPost']))->not->toBeFalse();
});

it('marks a media private before the sizes of the module are made', function (): void {
    Functions\when('did_action')->justReturn(0);
    $media = new WordPressMedia((new TemporaryStorage)->storage, new MediaConfig('https://media.client.fr'));

    $media->register();

    expect(has_filter('wp_generate_attachment_metadata', [$media->privateMedia(), 'generated']))
        ->toBeLessThan(has_filter('wp_generate_attachment_metadata', [$media->declaredSizes(), 'generate']));
});

it('queues the other sizes only when asynchronous thumbnails are asked for', function (bool $async): void {
    Functions\when('did_action')->justReturn(0);

    (new WordPressMedia((new TemporaryStorage)->storage, new MediaConfig('https://media.client.fr', asyncThumbnails: $async)))->register();

    expect(has_filter('wp_generate_attachment_metadata', 'Pollora\\Media\\Infrastructure\\WordPress\\AsyncThumbnails->queueTheRest()') !== false)->toBe($async);
})->with([true, false]);
