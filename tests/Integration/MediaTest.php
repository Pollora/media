<?php

declare(strict_types=1);

use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\FocalPoints;
use Pollora\Media\Infrastructure\WordPress\ThumbnailRoute;
use Pollora\Media\Infrastructure\WordPress\UrlReplacement;
use Pollora\Media\Tests\Integration\Support\Library;

/**
 * What the Brain Monkey doubles cannot show: the module registered on a real WordPress (its filters, its stream
 * wrapper, its post meta) and a real upload, resize, REST write and delete going through it, with the real image
 * editors writing to the disk behind the wrapper.
 */
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/user.php';

/**
 * Uploads a JPEG the way the media screen does.
 */
function uploadPhoto(object $test, string $name = 'photo.jpg'): int
{
    $source = Library::jpeg();
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $source], 0);

    expect($id)->toBeInt();
    $test->attachments[] = $id;

    return $id;
}

beforeEach(function (): void {
    $this->attachments = [];
    $this->users = [];
    Library::boot();
    wp_set_current_user(0);
});

afterEach(function (): void {
    foreach ($this->attachments as $id) {
        wp_delete_attachment($id, true);
    }

    foreach ($this->users as $id) {
        wp_delete_user($id);
    }

    wp_set_current_user(0);
});

it('moves the upload directory to the wrapper and to the public URL, and writes a file to the disk behind it', function (): void {
    $dirs = wp_upload_dir();
    $bits = wp_upload_bits('note.txt', null, 'hello');

    expect($dirs['basedir'])->toBe('pollora://uploads')
        ->and($dirs['baseurl'])->toBe(Library::URL)
        ->and($dirs['url'])->toBe(Library::URL.$dirs['subdir'])
        ->and($bits['error'])->toBeFalse()
        ->and($bits['url'])->toBe(Library::URL.$dirs['subdir'].'/note.txt')
        ->and(file_get_contents(Library::root().$dirs['subdir'].'/note.txt'))->toBe('hello');

    unlink(Library::root().$dirs['subdir'].'/note.txt');
});

it('stores an uploaded image and the sizes WordPress and the module make on the disk, and deletes them all with the media', function (): void {
    $id = uploadPhoto($this);
    $metadata = wp_get_attachment_metadata($id);
    $stem = pathinfo($metadata['file'], PATHINFO_DIRNAME);
    $deleted = [];
    add_action('pollora/media/deleted', function (int $postId, array $files) use (&$deleted): void {
        $deleted = $files;
    }, 10, 2);

    // WordPress's own sizes went through the wrapper, the editor read the image back from it
    expect($metadata['width'])->toBe(1200)
        ->and(Library::files())->toContain($metadata['file'])
        ->and(Library::files())->toContain($stem.'/'.$metadata['sizes']['thumbnail']['file'])
        // the eager size of the module is recorded in the metadata and exists
        ->and($metadata['sizes'])->toHaveKey('square')
        ->and(Library::files())->toContain($stem.'/'.$metadata['sizes']['square']['file'])
        ->and(wp_get_attachment_url($id))->toBe(Library::URL.'/'.$metadata['file']);

    wp_delete_attachment($id, true);
    remove_all_actions('pollora/media/deleted');

    expect(array_filter(Library::files(), static fn (string $file): bool => str_contains($file, 'photo')))->toBe([])
        ->and($deleted)->toContain($metadata['file']);
});

it('builds the URL of a declared size and makes the file when its URL is first asked for', function (): void {
    $id = uploadPhoto($this, 'landscape.jpg');
    $metadata = wp_get_attachment_metadata($id);
    $media = Library::boot();

    [$url, $width, $height] = wp_get_attachment_image_src($id, 'card');
    $path = (string) parse_url($url, PHP_URL_PATH);
    $route = new ThumbnailRoute($media->thumbnailService(), new MediaConfig(Library::URL));
    $file = $route->keyOf($path);

    expect($url)->toStartWith(Library::URL.'/'.dirname($metadata['file']).'/landscape-card-600x400-')
        ->and($url)->toEndWith('.webp')
        ->and([$width, $height])->toBe([600, 400])
        // nothing is made at upload for a size that is not eager, and nothing is asked of the disk to build the URL
        ->and(Library::files())->not->toContain($file);

    $served = $route->resolve($path);

    expect($served?->status)->toBe(200)
        ->and($served->contentType)->toBe('image/webp')
        ->and(Library::files())->toContain($file);
});

it('declares the focal point meta on a real WordPress, writes it over REST for an editor only, and makes the covers around it', function (): void {
    $id = uploadPhoto($this, 'focus.jpg');
    $editor = wp_insert_user(['user_login' => 'media-editor-'.uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    $subscriber = wp_insert_user(['user_login' => 'media-reader-'.uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
    $this->users = [$editor, $subscriber];

    $write = function (array $point) use ($id): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/wp/v2/media/'.$id);
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['meta' => [FocalPoints::META => $point]]));

        return rest_do_request($request);
    };

    expect(registered_meta_key_exists('post', FocalPoints::META, 'attachment'))->toBeTrue();

    wp_set_current_user($subscriber);
    $forbidden = $write(['x' => 0.25, 'y' => 0.75]);

    wp_set_current_user($editor);
    $outOfRange = $write(['x' => 2, 'y' => 0.5]);
    $accepted = $write(['x' => 0.25, 'y' => 0.75]);
    $metadata = wp_get_attachment_metadata($id);

    expect($forbidden->get_status())->toBeIn([401, 403])
        ->and($outOfRange->get_status())->toBe(400)
        ->and($accepted->get_status())->toBe(200)
        ->and(get_post_meta($id, FocalPoints::META, true))->toBe(['x' => 0.25, 'y' => 0.75])
        // the eager cover is made again around the point, under a name that says it
        ->and($metadata['sizes']['square']['file'])->toContain('-f0250_0750-')
        ->and(Library::files())->toContain(dirname($metadata['file']).'/'.$metadata['sizes']['square']['file']);
});

it('replaces the legacy URLs of the library in the content, and tells from the table whether a file is private', function (): void {
    $content = apply_filters('the_content', '<img src="'.Library::LEGACY_URL.'/2026/11/old.jpg">');
    $id = uploadPhoto($this, 'secret.jpg');
    $file = (string) get_post_meta($id, '_wp_attached_file', true);
    $private = Library::boot()->privateMedia();

    expect($content)->toContain(Library::URL.'/2026/11/old.jpg')
        ->and($content)->not->toContain('old.example.test')
        ->and($private->isPrivateFile($file))->toBeFalse();

    $private->setPrivate($id, true);

    expect($private->isPrivateFile($file))->toBeTrue()
        ->and(get_post_meta($id, '_pollora_media_private', true))->toBe('1');
});

it('counts then replaces a media URL in the tables of the database, serialized values included', function (): void {
    /** @var wpdb $wpdb */
    global $wpdb;

    $old = 'https://replace.example.test/'.uniqid().'/uploads';
    $new = Library::URL;
    $id = wp_insert_post(['post_title' => 'URL replacement', 'post_status' => 'draft', 'post_content' => '<img src="'.$old.'/a.jpg">']);
    add_post_meta($id, '_pollora_probe', ['src' => $old.'/b.jpg']);
    $stored = static fn (): ?string => $wpdb->get_var($wpdb->prepare('SELECT post_content FROM %i WHERE ID = %d', $wpdb->posts, $id));
    $replacement = new UrlReplacement($wpdb);

    $counted = $replacement->replace($old, $new);

    expect($wpdb->last_error)->toBe('')
        ->and($counted['posts.post_content'])->toBe(1)
        ->and($counted['postmeta.meta_value'])->toBe(1)
        // a dry run writes nothing
        ->and($stored())->toContain($old.'/a.jpg');

    $changed = $replacement->replace($old, $new, dryRun: false);

    expect($wpdb->last_error)->toBe('')
        ->and($changed)->toBe($counted)
        ->and($stored())->toBe('<img src="'.$new.'/a.jpg">')
        ->and(get_post_meta($id, '_pollora_probe', true))->toBe(['src' => $new.'/b.jpg']);

    wp_delete_post($id, true);
});
