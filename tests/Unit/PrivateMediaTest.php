<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\AttachmentFiles;
use Pollora\Media\Infrastructure\WordPress\PrivateMedia;
use Pollora\Media\Tests\Fixtures\SigningStorage;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->storage = new SigningStorage($this->disk->storage);
    $this->config = new MediaConfig('https://media.client.fr', [], 'public, max-age=60', '+2 hours');
    $this->meta = [];
    Functions\when('get_post_meta')->alias(fn (int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? '');
    Functions\when('update_post_meta')->alias(function (int $id, string $key, mixed $value): bool {
        $this->meta[$id][$key] = $value;

        return true;
    });
    Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
        unset($this->meta[$id][$key]);

        return true;
    });
    Functions\when('wp_get_attachment_metadata')->justReturn([
        'file' => '2026/10/photo.jpg',
        'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg'], 'medium' => ['file' => 'photo-300x200.jpg']],
        'original_image' => 'photo-original.jpg',
    ]);
    $this->meta[7]['_wp_attached_file'] = '2026/10/photo.jpg';
    $this->private = new PrivateMedia($this->storage, $this->config, new AttachmentFiles);
});

it('hooks the URL of a media, of an image size, and the srcset, and the creation of a media', function (): void {
    Filters\expectAdded('image_downsize')->once();
    Filters\expectAdded('wp_get_attachment_url')->once();
    Filters\expectAdded('wp_get_attachment_image_src')->once();
    Filters\expectAdded('wp_calculate_image_srcset')->once();
    Filters\expectAdded('wp_generate_attachment_metadata')->once();

    $this->private->register();
});

it('lists every file of a media: the original, the sizes, the original image, and what a plugin declared', function (): void {
    Filters\expectApplied('pollora/media/attachment_files')->once()->andReturn(['2026/10/photo.webp']);

    expect((new AttachmentFiles)->for(7))->toBe([
        '2026/10/photo.jpg',
        '2026/10/photo-150x150.jpg',
        '2026/10/photo-300x200.jpg',
        '2026/10/photo-original.jpg',
        '2026/10/photo.webp',
    ]);
});

it('leaves a public media alone, without asking the storage to sign anything', function (): void {
    expect($this->private->isPrivate(7))->toBeFalse()
        ->and($this->private->url('https://media.client.fr/2026/10/photo.jpg', 7))->toBe('https://media.client.fr/2026/10/photo.jpg')
        ->and($this->storage->signed)->toBe(0);
});

it('signs the URL of a private media, with the expiry of the configuration', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';

    $url = $this->private->url('https://media.client.fr/2026/10/photo.jpg', 7);

    expect($url)->toStartWith('https://bucket.s3.test/2026/10/photo.jpg?X-Expires=')
        ->and((int) explode('X-Expires=', $url)[1])->toBeGreaterThan(time() + 7000)
        ->and((int) explode('X-Expires=', $url)[1])->toBeLessThanOrEqual(time() + 7201);
});

it('signs the size of an image and every source of a srcset, for a private media only', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';
    $image = $this->private->imageSrc(['https://media.client.fr/2026/10/photo-150x150.jpg', 150, 150, true], 7);
    $sources = $this->private->srcset([
        150 => ['url' => 'https://media.client.fr/2026/10/photo-150x150.jpg', 'descriptor' => 'w', 'value' => 150],
        300 => ['url' => 'https://media.client.fr/2026/10/photo-300x200.jpg', 'descriptor' => 'w', 'value' => 300],
    ], [], '', [], 7);

    expect($image[0])->toStartWith('https://bucket.s3.test/2026/10/photo-150x150.jpg?')
        ->and($image[1])->toBe(150)
        ->and($sources[150]['url'])->toStartWith('https://bucket.s3.test/2026/10/photo-150x150.jpg?')
        ->and($sources[300]['descriptor'])->toBe('w');

    $this->meta[7][PrivateMedia::META] = '';
    $public = new PrivateMedia($this->storage, $this->config, new AttachmentFiles);
    expect($public->imageSrc(['https://media.client.fr/a.jpg', 1, 1, false], 7)[0])->toBe('https://media.client.fr/a.jpg')
        ->and($public->imageSrc(false, 7))->toBeFalse()
        ->and($public->srcset(false, [], '', [], 7))->toBeFalse();
});

it('leaves a URL of another host as it is, and lets a filter change the signed URL', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';
    Filters\expectApplied('pollora/media/signed_url')->once()->andReturn('https://cdn.client.fr/signed/photo.jpg');

    expect($this->private->url('https://elsewhere.test/photo.jpg', 7))->toBe('https://elsewhere.test/photo.jpg')
        ->and($this->private->url('https://media.client.fr/2026/10/photo.jpg', 7))->toBe('https://cdn.client.fr/signed/photo.jpg');
});

it('serves the plain URL when the disk cannot sign, rather than a broken one', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';
    $local = new PrivateMedia($this->disk->storage, $this->config, new AttachmentFiles);

    expect($local->url('https://media.client.fr/2026/10/photo.jpg', 7))->toBe('https://media.client.fr/2026/10/photo.jpg');
});

it('lets a filter decide the visibility of a media, once per media in a request', function (): void {
    $calls = 0;
    Filters\expectApplied('pollora/media/is_private')->andReturnUsing(function (bool $stored, int $id) use (&$calls): bool {
        $calls++;

        return $id === 7;
    });

    expect($this->private->isPrivate(7))->toBeTrue()
        ->and($this->private->isPrivate(7))->toBeTrue()
        ->and($this->private->isPrivate(8))->toBeFalse()
        ->and($calls)->toBe(2);
});

it('makes a media private on the disk, in the database, and says so', function (): void {
    foreach (['2026/10/photo.jpg', '2026/10/photo-150x150.jpg', '2026/10/photo-300x200.jpg', '2026/10/photo-original.jpg'] as $file) {
        $this->disk->storage->write($file, 'x');
    }
    Actions\expectDone('pollora/media/visibility_changed')->once()->with(7, true);

    $report = $this->private->setPrivate(7, true);

    expect($report['failed'])->toBe([])
        ->and($report['changed'])->toHaveCount(4)
        ->and($this->storage->visibilities['2026/10/photo-150x150.jpg'])->toBe('private')
        ->and($this->meta[7][PrivateMedia::META])->toBe('1')
        ->and($this->private->isPrivate(7))->toBeTrue();
});

it('makes it public again, reports a file that is not on the disk, and forgets the flag', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';
    $this->disk->storage->write('2026/10/photo.jpg', 'x');
    Actions\expectDone('pollora/media/visibility_changed')->once()->with(7, false);

    $report = $this->private->setPrivate(7, false);

    expect($report['changed'])->toBe(['2026/10/photo.jpg'])
        ->and($report['failed'])->toContain('2026/10/photo-150x150.jpg')
        ->and($this->meta[7])->not->toHaveKey(PrivateMedia::META);
});

it('marks a media private when it is created, if the filter says so', function (): void {
    $this->disk->storage->write('2026/10/photo.jpg', 'x');
    Filters\expectApplied('pollora/media/is_private')->andReturn(true);
    Actions\expectDone('pollora/media/visibility_changed')->once()->with(7, true);
    $metadata = ['file' => '2026/10/photo.jpg'];

    expect($this->private->generated($metadata, 7, 'create'))->toBe($metadata)
        ->and($this->meta[7][PrivateMedia::META])->toBe('1');
});

it('does not mark anything private on an update of the metadata', function (): void {
    Filters\expectApplied('pollora/media/is_private')->never();
    Actions\expectDone('pollora/media/visibility_changed')->never();

    $this->private->generated(['file' => 'x.jpg'], 7, 'update');
});

it('leaves the URL of the media plain while WordPress builds the URL of a size from it, then signs the size', function (): void {
    $this->meta[7][PrivateMedia::META] = '1';

    $this->private->startDownsize(false);
    $plain = $this->private->url('https://media.client.fr/2026/10/photo.jpg', 7);
    $size = $this->private->imageSrc(['https://media.client.fr/2026/10/photo-150x150.jpg', 150, 150, true], 7);
    $after = $this->private->url('https://media.client.fr/2026/10/photo.jpg', 7);

    expect($plain)->toBe('https://media.client.fr/2026/10/photo.jpg')
        ->and($size[0])->toStartWith('https://bucket.s3.test/2026/10/photo-150x150.jpg?')
        ->and($after)->toStartWith('https://bucket.s3.test/2026/10/photo.jpg?');
});
