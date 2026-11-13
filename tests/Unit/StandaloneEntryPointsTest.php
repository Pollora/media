<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\ImageSize;
use Pollora\Media\Infrastructure\WordPress\StreamWrapper;
use Pollora\Media\Infrastructure\WordPress\WordPressMedia;
use Pollora\Media\Media;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

afterEach(function (): void {
    ImageSize::reset();
});

it('declares a size on a registry shared by every call, signed with a salt of WordPress', function (): void {
    Functions\expect('wp_salt')->with('pollora/media')->andReturn('salt');

    $declaration = ImageSize::add('card');
    $declaration->cover(600, 400);

    expect(ImageSize::registry()->get('card')->width)->toBe(600)
        ->and(ImageSize::registry())->toBe(ImageSize::registry());
});

it('forgets every declaration on reset', function (): void {
    Functions\when('wp_salt')->justReturn('salt');
    ImageSize::add('card')->cover(600, 400);
    $before = ImageSize::registry();

    ImageSize::reset();

    expect(ImageSize::registry())->not->toBe($before)
        ->and(ImageSize::registry()->all())->toBe([]);
});

it('registers the wrapper and the hooks of the media on a disk it is given', function (): void {
    Functions\when('wp_salt')->justReturn('salt');
    Filters\expectAdded('upload_dir')->once();

    $disk = new TemporaryStorage;
    $media = Media::register(new Filesystem(new LocalFilesystemAdapter($disk->root)), new MediaConfig('https://media.client.fr'));

    expect($media)->toBeInstanceOf(WordPressMedia::class)
        ->and(in_array('pollora', stream_get_wrappers(), true))->toBeTrue();

    StreamWrapper::unregister();
});
