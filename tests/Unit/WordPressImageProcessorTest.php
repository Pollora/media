<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Domain\Exceptions\ImageProcessingFailedException;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\StreamWrapper;
use Pollora\Media\Infrastructure\WordPress\WordPressImageProcessor;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

/**
 * The image editor of WordPress: a double that records what it is asked, and answers with what a failure
 * of the real one looks like (an error object, which `is_wp_error()` recognises).
 */
beforeEach(function (): void {
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->error = new class('boom')
    {
        public function __construct(private readonly string $message) {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    };
    $this->disk = new TemporaryStorage;
    StreamWrapper::register($this->disk->storage, new MediaConfig('https://media.client.fr'), static function (string $path, int $size): void {});
    $this->editor = new class
    {
        /** @var list<mixed> */
        public array $calls = [];

        public mixed $cropResult = true;

        public mixed $saveResult = null;

        public function set_quality(int $quality): void
        {
            $this->calls[] = ['quality', $quality];
        }

        public function crop(int $x, int $y, int $width, int $height, int $destWidth, int $destHeight): mixed
        {
            $this->calls[] = ['crop', $x, $y, $width, $height, $destWidth, $destHeight];

            return $this->cropResult;
        }

        public function save(string $path, string $mime): mixed
        {
            $this->calls[] = ['save', $path, $mime];
            file_put_contents($path, 'webp-bytes');

            return $this->saveResult ?? ['width' => 600, 'height' => 400, 'mime-type' => $mime];
        }
    };
    $this->supported = true;
    Functions\when('wp_check_filetype')->alias(fn (string $file): array => ['type' => str_ends_with($file, '.png') ? 'image/png' : (str_ends_with($file, '.jpg') ? 'image/jpeg' : false)]);
    Functions\when('wp_image_editor_supports')->alias(fn (array $args): bool => $this->supported);
    Functions\when('wp_get_image_editor')->alias(fn (string $path): mixed => $this->editorResult ?? $this->editor);
    Functions\when('is_wp_error')->alias(fn (mixed $thing): bool => $thing === $this->error);
});

afterEach(function (): void {
    StreamWrapper::unregister();
});

it('crops a cover size from the region the geometry gives, writes it in the format asked, and reports the file', function (): void {
    $this->sizes->add('card')->cover(600, 400)->webp(75);
    $spec = $this->sizes->get('card');
    $this->editor->saveResult = ['width' => 600, 'height' => 400, 'mime-type' => 'image/webp'];

    $made = (new WordPressImageProcessor)->make('2026/10/photo.jpg', '2026/10/photo-card.webp', $spec, 3000, 2000);

    expect($made)->toBe(['width' => 600, 'height' => 400, 'mime' => 'image/webp', 'filesize' => 10])
        ->and($this->editor->calls[0])->toBe(['quality', 75])
        ->and($this->editor->calls[1][0])->toBe('crop')
        ->and($this->editor->calls[2])->toBe(['save', 'pollora://uploads/2026/10/photo-card.webp', 'image/webp'])
        ->and($this->disk->storage->exists('2026/10/photo-card.webp'))->toBeTrue();
});

it('keeps the format of the original when the size asks for it', function (): void {
    $this->sizes->add('wide')->scale(width: 1500)->original();
    $spec = $this->sizes->get('wide');

    (new WordPressImageProcessor)->make('2026/10/photo.png', '2026/10/photo-wide.png', $spec, 3000, 2000);

    expect(end($this->editor->calls))->toBe(['save', 'pollora://uploads/2026/10/photo-wide.png', 'image/png']);
});

it('crops the whole image for a size that is not a cover', function (): void {
    $this->sizes->add('wide')->scale(width: 1500)->webp();
    $spec = $this->sizes->get('wide');

    (new WordPressImageProcessor)->make('2026/10/photo.jpg', '2026/10/photo-wide.webp', $spec, 3000, 2000);

    expect($this->editor->calls[1])->toBe(['crop', 0, 0, 3000, 2000, 1500, 1000]);
});

it('refuses a format the editor cannot write', function (): void {
    $this->supported = false;
    $this->sizes->add('card')->cover(600, 400)->avif();
    $spec = $this->sizes->get('card');

    expect(fn () => (new WordPressImageProcessor)->make('2026/10/photo.jpg', 'x.avif', $spec, 3000, 2000))->toThrow(ImageProcessingFailedException::class);
});

it('refuses an original whose type WordPress does not know', function (): void {
    $this->sizes->add('card')->cover(600, 400)->original();
    $spec = $this->sizes->get('card');

    expect(fn () => (new WordPressImageProcessor)->make('2026/10/photo.bmp', 'x.bmp', $spec, 3000, 2000))->toThrow(ImageProcessingFailedException::class);
});

it('passes on the reason when the editor cannot open the image', function (): void {
    $this->editorResult = $this->error;
    $this->sizes->add('card')->cover(600, 400);
    $spec = $this->sizes->get('card');

    expect(fn () => (new WordPressImageProcessor)->make('2026/10/photo.jpg', 'x.webp', $spec, 3000, 2000))->toThrow(ImageProcessingFailedException::class, 'boom');
});

it('passes on the reason when the crop fails', function (): void {
    $this->editor->cropResult = $this->error;
    $this->sizes->add('card')->cover(600, 400);
    $spec = $this->sizes->get('card');

    expect(fn () => (new WordPressImageProcessor)->make('2026/10/photo.jpg', 'x.webp', $spec, 3000, 2000))->toThrow(ImageProcessingFailedException::class, 'boom');
});

it('passes on the reason when the file cannot be saved', function (): void {
    $this->editor->saveResult = $this->error;
    $this->sizes->add('card')->cover(600, 400);
    $spec = $this->sizes->get('card');

    expect(fn () => (new WordPressImageProcessor)->make('2026/10/photo.jpg', 'x.webp', $spec, 3000, 2000))->toThrow(ImageProcessingFailedException::class, 'boom');
});
