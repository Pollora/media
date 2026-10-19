<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;
use Pollora\Media\Infrastructure\WordPress\StreamWrapper;
use Pollora\Media\Infrastructure\WordPress\WordPressMedia;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->config = new MediaConfig('https://media.client.fr', ['https://old.test/content/uploads']);
    $this->media = new WordPressMedia($this->disk->storage, $this->config);
});

afterEach(function (): void {
    StreamWrapper::unregister();
});

it('hooks WordPress at the points of the specification', function (): void {
    Filters\expectAdded('upload_dir')->once();
    Filters\expectAdded('pre_wp_unique_filename_file_list')->once();
    Filters\expectAdded('wp_resource_hints')->once();
    Filters\expectAdded('the_content')->once();
    Filters\expectAdded('render_block')->once();
    Brain\Monkey\Actions\expectAdded('delete_attachment')->once();
    Filters\expectAdded('wp_privacy_exports_dir')->once();

    $this->media->register();

    expect(in_array('pollora', stream_get_wrappers(), true))->toBeTrue();
});

it('does not hook the content when no legacy URL is set', function (): void {
    $media = new WordPressMedia($this->disk->storage, new MediaConfig('https://media.client.fr'));
    Filters\expectAdded('the_content')->never();
    Filters\expectAdded('render_block')->never();
    Filters\expectAdded('upload_dir')->once();

    $media->register();
});

it('points the upload directory at the wrapper and the public URL, keeping the date folder', function (): void {
    $dirs = $this->media->uploadDir([
        'path' => '/var/www/public/content/uploads/2026/10',
        'url' => 'https://site.test/content/uploads/2026/10',
        'subdir' => '/2026/10',
        'basedir' => '/var/www/public/content/uploads',
        'baseurl' => 'https://site.test/content/uploads',
        'error' => false,
    ]);

    expect($dirs)->toBe([
        'path' => 'pollora://uploads/2026/10',
        'url' => 'https://media.client.fr/2026/10',
        'subdir' => '/2026/10',
        'basedir' => 'pollora://uploads',
        'baseurl' => 'https://media.client.fr',
        'error' => false,
    ]);
});

it('keeps the folder WordPress adds for a site of a network, and an upload directory without a date', function (): void {
    $site = $this->media->uploadDir([
        'path' => '/var/www/public/content/uploads/sites/3/2026/10',
        'url' => 'https://site.test/content/uploads/sites/3/2026/10',
        'subdir' => '/2026/10',
        'basedir' => '/var/www/public/content/uploads/sites/3',
        'baseurl' => 'https://site.test/content/uploads/sites/3',
        'error' => false,
    ]);
    $flat = $this->media->uploadDir([
        'path' => '/var/www/public/content/uploads',
        'url' => 'https://site.test/content/uploads',
        'subdir' => '',
        'basedir' => '/var/www/public/content/uploads',
        'baseurl' => 'https://site.test/content/uploads',
        'error' => false,
    ]);

    expect($site['basedir'])->toBe('pollora://uploads/sites/3')
        ->and($site['path'])->toBe('pollora://uploads/sites/3/2026/10')
        ->and($site['baseurl'])->toBe('https://media.client.fr/sites/3')
        ->and($site['url'])->toBe('https://media.client.fr/sites/3/2026/10')
        ->and($flat['path'])->toBe('pollora://uploads')
        ->and($flat['url'])->toBe('https://media.client.fr');
});

it('leaves an upload directory that WordPress could not make as it found it', function (): void {
    $broken = ['path' => '/x', 'url' => 'u', 'subdir' => '', 'basedir' => '/x', 'baseurl' => 'u', 'error' => 'Unable to create directory'];

    expect($this->media->uploadDir($broken))->toBe($broken);
});

it('hands WordPress an empty file list for the wrapper, so it does not list a remote folder', function (): void {
    expect($this->media->uniqueFilenameFileList(null, 'pollora://2026/10', 'photo.jpg'))->toBe([])
        ->and($this->media->uniqueFilenameFileList(null, '/var/www/public/content/uploads/2026/10', 'photo.jpg'))->toBeNull()
        ->and($this->media->uniqueFilenameFileList(['x'], 'pollora://2026/10', 'photo.jpg'))->toBe(['x']);
});

it('deletes the files a plugin declared with the media, besides those WordPress knows', function (): void {
    $this->media->register();
    mkdir($this->disk->root.'/2026', 0777, true);
    file_put_contents($this->disk->root.'/2026/extra.webp', 'x');
    file_put_contents($this->disk->root.'/2026/keep.jpg', 'x');
    Functions\when('wp_get_attachment_metadata')->justReturn(['file' => '2026/keep.jpg']);
    Filters\expectApplied('pollora/media/attachment_files')->with([], 42)->andReturn(['2026/extra.webp', '2026/missing.webp']);
    Brain\Monkey\Actions\expectDone('pollora/media/deleted')->once()->with(42, ['2026/keep.jpg', '2026/extra.webp', '2026/missing.webp']);

    $this->media->deleteAttachment(42);
    $this->media->deletedPost(42);
    $this->media->deletedPost(43);

    expect(file_exists($this->disk->root.'/2026/extra.webp'))->toBeFalse()
        ->and(file_exists($this->disk->root.'/2026/keep.jpg'))->toBeTrue();
});

it('adds a dns-prefetch for the media domain when it differs from the site, and only then', function (): void {
    Functions\when('home_url')->justReturn('https://site.test');

    expect($this->media->resourceHints(['//fonts.example'], 'dns-prefetch'))->toBe(['//fonts.example', '//media.client.fr'])
        ->and($this->media->resourceHints(['a'], 'preconnect'))->toBe(['a'])
        ->and($this->media->resourceHints(['//media.client.fr'], 'dns-prefetch'))->toBe(['//media.client.fr']);

    $same = new WordPressMedia($this->disk->storage, new MediaConfig('https://site.test/content/uploads'));
    expect($same->resourceHints([], 'dns-prefetch'))->toBe([]);
});

it('rewrites legacy URLs in the content and in rendered blocks', function (): void {
    expect($this->media->content('<img src="https://old.test/content/uploads/2026/a.jpg">'))->toBe('<img src="https://media.client.fr/2026/a.jpg">')
        ->and($this->media->block('<img src="https://old.test/content/uploads/2026/a.jpg">', []))->toBe('<img src="https://media.client.fr/2026/a.jpg">');
});

it('tells the library of each file stored, with its path and size', function (): void {
    Brain\Monkey\Actions\expectDone('pollora/media/stored')->once()->with('2026/10/a.jpg', 5);
    $this->media->register();

    file_put_contents('pollora://uploads/2026/10/a.jpg', 'bytes');
});

it('deletes the sizes made on demand with the media, though WordPress does not know them', function (): void {
    $sizes = new ImageSizes(fn (): string => 'secret-key');
    $sizes->add('card')->cover(600, 400);
    $media = new WordPressMedia($this->disk->storage, $this->config, null, $sizes);
    $meta = ['file' => '2026/10/photo.jpg', 'width' => 3000, 'height' => 2000];
    $file = $sizes->names()->for('2026/10/photo.jpg', $sizes->get('card'), 3000, 2000);
    mkdir($this->disk->root.'/2026/10', 0777, true);
    $this->disk->storage->write($file, 'made on demand');
    Functions\when('wp_get_attachment_metadata')->justReturn($meta);

    $media->deleteAttachment(9);

    expect($this->disk->storage->exists($file))->toBeFalse();
});

it('answers a missing size on its own route with the default driver', function (): void {
    Brain\Monkey\Actions\expectAdded('parse_request')->once();

    $this->media->register();
});

it('registers no fallback route when a service makes the sizes', function (ThumbnailDriverSettings $settings): void {
    $media = new WordPressMedia($this->disk->storage, $this->config, thumbnails: $settings);
    Brain\Monkey\Actions\expectAdded('parse_request')->never();

    $media->register();

    expect($media->thumbnailDriver()->makesFiles())->toBeFalse();
})->with([
    'bunny' => [new ThumbnailDriverSettings(driver: 'bunny')],
    'imgproxy' => [new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F')],
]);

it('keeps the default driver, and says why, when the driver of the sizes is badly set up', function (ThumbnailDriverSettings $settings): void {
    $log = tempnam(sys_get_temp_dir(), 'media-log');
    $previous = ini_set('error_log', $log);

    try {
        $media = new WordPressMedia($this->disk->storage, $this->config, thumbnails: $settings);
    } finally {
        ini_set('error_log', (string) $previous);
    }

    expect($media->thumbnailDriver()->makesFiles())->toBeTrue()
        ->and((string) file_get_contents($log))->toContain('The default driver is used')->not->toContain('736563726574');
    unlink($log);
})->with([
    'imgproxy without a URL' => [new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F')],
    'imgproxy with a key that is not hexadecimal' => [new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: 'secret', imgproxySalt: '68656C6C6F')],
    'imgproxy on s3 without a bucket' => [new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F', imgproxySource: 's3')],
    'unknown driver' => [new ThumbnailDriverSettings(driver: 'cloudfront')],
]);

it('reports a driver of the sizes that could not be used, and a size the service refuses', function (): void {
    $sizes = new ImageSizes(fn (): string => 'k');
    $sizes->add('default')->scale(width: 100);
    $good = new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '736563726574', imgproxySalt: '68656C6C6F');
    $log = tempnam(sys_get_temp_dir(), 'media-log');
    $previous = ini_set('error_log', $log);

    try {
        $broken = new WordPressMedia($this->disk->storage, $this->config, sizes: $sizes, thumbnails: new ThumbnailDriverSettings(driver: 'imgproxy'));
    } finally {
        ini_set('error_log', (string) $previous);
        unlink($log);
    }

    expect($broken->thumbnailProblems())->toHaveCount(1)->and($broken->thumbnailProblems()[0])->toContain('"imgproxy"')
        ->and((new WordPressMedia($this->disk->storage, $this->config, sizes: $sizes, thumbnails: $good))->thumbnailProblems())->toHaveCount(1)->and((new WordPressMedia($this->disk->storage, $this->config, sizes: $sizes, thumbnails: $good))->thumbnailProblems()[0])->toContain('default')
        ->and($this->media->thumbnailProblems())->toBe([]);
});
