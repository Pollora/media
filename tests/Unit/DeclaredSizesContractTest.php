<?php

declare(strict_types=1);

/*
 * SEC-06 of the Pollora harness catalogue: on-the-fly thumbnails only accept declared sizes.
 *
 * Each guarantee has a witness: the same request, correctly formed, is made, so a refusal below is
 * the guard's doing and not a broken fixture.
 */

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Application\Services\ThumbnailService;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;
use Pollora\Media\Domain\Services\ImgproxySigner;
use Pollora\Media\Infrastructure\BunnyThumbnailDriver;
use Pollora\Media\Infrastructure\ImgproxyThumbnailDriver;
use Pollora\Media\Infrastructure\Locking\FlockGenerationLock;
use Pollora\Media\Infrastructure\ThumbnailDrivers;
use Pollora\Media\Infrastructure\WordPress\ThumbnailRoute;
use Pollora\Media\Tests\Fixtures\CountingStorage;
use Pollora\Media\Tests\Fixtures\FakeImageProcessor;
use Pollora\Media\Tests\Fixtures\PngHeader;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

const SEC06_KEY_HEX = 'abababababababababababababababab';
const SEC06_SALT_HEX = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->counting = new CountingStorage($this->disk->storage);
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75);
    $this->sizes->add('hero')->scale(width: 1200)->widths([400]);
    $this->disk->storage->write('2026/10/photo.png', PngHeader::bytes(3000, 2000).'x');
    $this->disk->storage->write('2026/10/other.png', PngHeader::bytes(3000, 2000).'x');
    $this->processor = new FakeImageProcessor($this->disk->storage);
    $this->lockDir = sys_get_temp_dir().'/pollora-media-sec06-locks-'.bin2hex(random_bytes(4));
    $this->service = new ThumbnailService(
        $this->counting,
        $this->sizes,
        $this->processor,
        new FlockGenerationLock($this->lockDir),
        new ThumbnailLimits(wait: 0),
        static fn (string $key): bool => false,
    );
    $this->card = $this->sizes->names()->for('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000);
    $this->config = new MediaConfig('https://media.client.fr');
});

afterEach(function (): void {
    foreach (glob($this->lockDir.'/*') ?: [] as $file) {
        unlink($file);
    }
    is_dir($this->lockDir) && rmdir($this->lockDir);
});

/**
 * Every file of the library, to prove a refused request wrote nothing.
 *
 * @return list<string>
 */
function sec06_files(TemporaryStorage $disk): array
{
    $files = array_column(iterator_to_array($disk->storage->list('2026/10')), 'path');
    sort($files);

    return $files;
}

// (1) An undeclared size cannot be asked for, whatever the parameter a visitor plays with.

it('makes the declared size once, as the witness of every refusal below', function (): void {
    $result = $this->service->serve($this->card);

    expect($result->status)->toBe(200)->and(array_column($this->processor->made, 'size'))->toBe(['card']);
});

it('makes nothing for a name that is not exactly the one the site would have written', function (string $mutation): void {
    $path = match ($mutation) {
        'wider' => str_replace('600x400', '601x400', $this->card),
        'taller' => str_replace('600x400', '600x401', $this->card),
        'other ratio' => str_replace('600x400', '600x300', $this->card),
        'huge width' => str_replace('600x400', '99999x400', $this->card),
        'zero' => str_replace('600x400', '0x0', $this->card),
        'other format' => str_replace('.webp', '.avif', $this->card),
        'jpeg for a webp size' => str_replace('.webp', '.jpg', $this->card),
        'undeclared name' => str_replace('-card-', '-banner-', $this->card),
        'quality in the name' => str_replace('-card-', '-card-q10-', $this->card),
        'extra width not declared' => str_replace('-card-', '-card-777w-', $this->card),
        'signature of another size' => preg_replace('/-[0-9a-f]{12}\./', '-'.$this->sizes->names()->signature('2026/10/photo.png', $this->sizes->get('hero')).'.', $this->card),
        'signature of another original' => preg_replace('/-[0-9a-f]{12}\./', '-'.$this->sizes->names()->signature('2026/10/other.png', $this->sizes->get('card')).'.', $this->card),
        'signature of nothing' => preg_replace('/-[0-9a-f]{12}\./', '-000000000000.', $this->card),
        'upper-case extension' => str_replace('.webp', '.WEBP', $this->card),
        'trailing newline' => $this->card."\n",
        'trailing space' => $this->card.' ',
        'second extension' => $this->card.'.php',
        'url-encoded dot' => str_replace('.webp', '%2ewebp', $this->card),
    };
    $before = sec06_files($this->disk);

    $result = $this->service->serve($path);

    expect($result->status)->toBe(404)
        ->and($result->body)->toBeNull()
        ->and($this->processor->made)->toBe([])
        ->and(sec06_files($this->disk))->toBe($before);
})->with([
    'wider', 'taller', 'other ratio', 'huge width', 'zero', 'other format', 'jpeg for a webp size', 'undeclared name',
    'quality in the name', 'extra width not declared', 'signature of another size', 'signature of another original',
    'signature of nothing', 'upper-case extension', 'trailing newline', 'trailing space', 'second extension', 'url-encoded dot',
]);

it('never reaches the storage for a request whose name cannot be the site\'s, so a flood costs a hash and not a disk read', function (): void {
    for ($i = 0; $i < 300; $i++) {
        $this->service->serve(sprintf('2026/10/photo-card-%dx%d-%012x.webp', 100 + $i, 50 + $i, $i));
    }

    expect($this->counting->existsCalls)->toBe(0)
        ->and($this->counting->readCalls)->toBe(0)
        ->and($this->processor->made)->toBe([]);
});

it('does not let a request pick the width or the height of a size: the dimensions are those the declaration gives', function (): void {
    // The signature is the right one, the dimensions are not: only the original can say what the size is.
    $forged = str_replace('600x400', '123x456', $this->card);

    expect($this->service->serve($forged)->status)->toBe(404)->and($this->processor->made)->toBe([]);
});

it('bounds the sizes that can ever exist for an image by what is declared (sizes x extra widths x one format)', function (): void {
    $made = [];

    foreach ($this->sizes->withVariants() as $spec) {
        $made[] = $this->sizes->names()->for('2026/10/photo.png', $spec, 3000, 2000);
    }

    foreach ($made as $path) {
        $this->service->serve($path);
    }

    expect($made)->toHaveCount(3)->and($this->processor->made)->toHaveCount(3);
});

// (2) The signature.

it('depends on the secret: a name signed with another key is refused', function (): void {
    $foreign = (new ImageSizes(fn (): string => 'another-key'));
    $foreign->add('card')->cover(600, 400)->webp(75);
    $path = $foreign->names()->for('2026/10/photo.png', $foreign->get('card'), 3000, 2000);

    expect($this->service->serve($path)->status)->toBe(404)->and($this->processor->made)->toBe([]);
});

it('changes with every parameter of the size, so an old URL does not survive a redefinition', function (): void {
    $names = $this->sizes->names();
    $base = $names->signature('2026/10/photo.png', new ImageSizeSpec('card', Fit::Cover, 600, 400));

    expect($names->signature('2026/10/photo.png', new ImageSizeSpec('card', Fit::Cover, 600, 400, quality: 50)))->not->toBe($base)
        ->and($names->signature('2026/10/photo.png', new ImageSizeSpec('card', Fit::Cover, 601, 400)))->not->toBe($base)
        ->and($names->signature('2026/10/photo.png', new ImageSizeSpec('card', Fit::Contain, 600, 400)))->not->toBe($base)
        ->and($names->signature('2026/10/other.png', new ImageSizeSpec('card', Fit::Cover, 600, 400)))->not->toBe($base);
});

it('compares signatures in constant time, and nowhere with an operator that stops at the first difference', function (): void {
    $service = (string) file_get_contents(__DIR__.'/../../src/Application/Services/ThumbnailService.php');

    expect($service)->toContain('hash_equals(')
        ->and(preg_match('/signature[^\n;]*(===|!==|==|!=)/i', $service))->toBe(0)
        ->and(preg_match('/(===|!==)[^\n;]*signature/i', $service))->toBe(0);
});

it('signs an imgproxy path with the key and the salt, and a path that was altered no longer matches', function (): void {
    $signer = new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX);
    $other = new ImgproxySigner(SEC06_SALT_HEX, SEC06_KEY_HEX);
    $path = '/card/plain/https://media.client.fr/a.jpg@webp';

    expect($signer->sign($path))->toBe($signer->sign($path))
        ->and($signer->sign('/hero/plain/https://media.client.fr/a.jpg@webp'))->not->toBe($signer->sign($path))
        ->and($signer->sign('/card/plain/https://evil.test/a.jpg@webp'))->not->toBe($signer->sign($path))
        ->and($other->sign($path))->not->toBe($signer->sign($path))
        ->and($signer->sign($path))->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

it('never puts the imgproxy key or salt in a URL, an exception or a trace', function (): void {
    $driver = new ImgproxyThumbnailDriver(new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX), $this->config, $this->sizes, 'https://img.client.fr');
    $url = $driver->url('2026/10/photo.jpg', new ImageSizeSpec('card', Fit::Cover, 600, 400), 3000, 2000);
    $leaks = [];

    try {
        new ImgproxySigner(SEC06_KEY_HEX.'zz', SEC06_SALT_HEX);
    } catch (InvalidArgumentException $e) {
        $leaks[] = $e->getMessage().$e->getTraceAsString();
    }

    try {
        new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX.'g');
    } catch (InvalidArgumentException $e) {
        $leaks[] = $e->getMessage().$e->getTraceAsString();
    }

    $reported = [];
    ThumbnailDrivers::make(
        new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: SEC06_KEY_HEX.'zz', imgproxySalt: SEC06_SALT_HEX),
        $this->sizes,
        $this->config,
        function (string $message) use (&$reported): void {
            $reported[] = $message;
        },
    );
    expect($reported)->toHaveCount(1)
        ->and($url)->not->toContain(SEC06_KEY_HEX)->and($url)->not->toContain(SEC06_SALT_HEX);

    foreach ([...$leaks, ...$reported, $url] as $text) {
        expect($text)->not->toContain(SEC06_KEY_HEX)->and($text)->not->toContain(SEC06_SALT_HEX);
    }
});

// (3) The source: nothing a visitor writes ever becomes the source of an image.

it('takes the source of an imgproxy URL from the library alone, whatever the key looks like', function (string $key): void {
    $driver = new ImgproxyThumbnailDriver(new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX), $this->config, $this->sizes, 'https://img.client.fr');
    $url = $driver->url($key, new ImageSizeSpec('card', Fit::Cover, 600, 400), 3000, 2000);

    expect($url)->toStartWith('https://img.client.fr/')
        ->and($url)->toContain('/card/plain/https://media.client.fr/')
        ->and(substr_count($url, '?'))->toBe(1)
        ->and(substr_count($url, '@'))->toBe(1);
})->with([
    'an option smuggled with @' => ['a.jpg@png'],
    'a query' => ['a.jpg?x=1'],
    'a percent' => ['a%2e.jpg'],
    'a leading slash' => ['/a.jpg'],
]);

it('uses the bucket of the library as the source in s3 mode, and no other', function (): void {
    $driver = new ImgproxyThumbnailDriver(new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX), $this->config, $this->sizes, 'https://img.client.fr', 'client-media');

    expect($driver->url('2026/a.jpg', new ImageSizeSpec('card', Fit::Cover, 600, 400), 3000, 2000))->toContain('/card/plain/s3://client-media/2026/a.jpg');
});

it('does not take a URL that is not the library\'s or the service\'s for one of its sizes', function (string $url): void {
    $imgproxy = new ImgproxyThumbnailDriver(new ImgproxySigner(SEC06_KEY_HEX, SEC06_SALT_HEX), $this->config, $this->sizes, 'https://img.client.fr');
    $bunny = new BunnyThumbnailDriver($this->config, $this->sizes);

    expect($imgproxy->sizeOf($url))->toBeNull()->and($bunny->sizeOf($url))->toBeNull();
})->with([
    'another host' => ['https://evil.test/card/plain/https://media.client.fr/a.jpg@webp'],
    'a host that starts the same' => ['https://img.client.fr.evil.test/card/plain/x'],
    'a file scheme' => ['file:///etc/passwd?class=card'],
    'a data scheme' => ['data:text/plain,card'],
]);

// (4) Traversal and schemes in a requested path.

it('answers 404 and touches no storage for a path that climbs, names a scheme or carries a control character', function (string $path): void {
    $result = $this->service->serve($path);

    expect($result->status)->toBe(404)
        ->and($this->counting->existsCalls)->toBe(0)
        ->and($this->processor->made)->toBe([]);
})->with([
    'dot dot' => ['2026/../photo-card-600x400-0123456789ab.webp'],
    'encoded in the key itself' => ['2026/10/..%2fphoto-card-600x400-0123456789ab.webp'],
    'double slash' => ['2026//photo-card-600x400-0123456789ab.webp'],
    'nul' => ["2026/photo\0-card-600x400-0123456789ab.webp"],
    'newline' => ["2026/photo\n-card-600x400-0123456789ab.webp"],
    'carriage return' => ["2026/photo\r-card-600x400-0123456789ab.webp"],
    'tab' => ["2026/photo\t-card-600x400-0123456789ab.webp"],
    'delete' => ["2026/photo\x7f-card-600x400-0123456789ab.webp"],
]);

it('cannot be given another source by a scheme in the path: a signed name only exists for a key of the library', function (string $key): void {
    $path = $this->sizes->names()->for($key, $this->sizes->get('card'), 3000, 2000);

    // Even correctly signed (which a visitor cannot do), the original has to be a file of the library, and there is none.
    expect($this->service->serve($path)->status)->toBe(404)->and($this->processor->made)->toBe([]);
})->with([
    'file' => ['file:/etc/passwd.png'],
    'php' => ['php:/filter/resource=x.png'],
    'http' => ['http:/evil.test/a.png'],
    's3' => ['s3:/other-bucket/a.png'],
]);

it('lets the route hand the service nothing but a library path with the module\'s prefix removed, once decoded', function (string $uri, ?string $key): void {
    $route = new ThumbnailRoute($this->service, $this->config);

    expect($route->keyOf($uri))->toBe($key);
})->with([
    'plain' => ['/2026/10/photo-card-600x400-0123456789ab.webp?x=1', '2026/10/photo-card-600x400-0123456789ab.webp'],
    'newline after the extension' => ['/2026/10/photo-card-600x400-0123456789ab.webp%0A', null],
    'not an image' => ['/2026/10/photo-card-600x400-0123456789ab.php', null],
    'a php file with an image name before it' => ['/shell.php/photo-card-600x400-0123456789ab.webp.php', null],
]);

it('refuses a traversal through the route, encoded or not, without touching the disk', function (string $uri): void {
    $route = new ThumbnailRoute($this->service, $this->config);
    $result = $route->resolve($uri);

    expect($result === null || $result->status === 404)->toBeTrue()
        ->and($this->counting->existsCalls)->toBe(0)
        ->and($this->processor->made)->toBe([]);
})->with([
    'dots' => ['/2026/../../photo-card-600x400-0123456789ab.webp'],
    'encoded dots' => ['/2026/%2e%2e/photo-card-600x400-0123456789ab.webp'],
    'encoded slash' => ['/2026%2f..%2fphoto-card-600x400-0123456789ab.webp'],
    'encoded nul' => ['/2026/photo%00-card-600x400-0123456789ab.webp'],
]);

// (5) Headers and cache.

it('caches a size that was made for good, a size nobody can make for nothing, and a stand-in not at all', function (): void {
    $made = $this->service->serve($this->card);
    $refused = $this->service->serve('2026/10/photo-banner-600x400-0123456789ab.webp');

    expect($made->headers['Cache-Control'])->toBe('public, max-age=31536000, immutable')
        ->and($refused->headers)->toBe([])
        ->and($refused->contentType)->toBe('');
});

it('serves only an image content type, derived from the extension the name was checked for, never from the request', function (): void {
    $result = $this->service->serve($this->card);

    expect($result->contentType)->toBe('image/webp')
        ->and(array_keys($result->headers))->toBe(['Cache-Control']);
});

it('refuses the sizes a visitor could use to read a file that is not an image: extension outside the image list', function (string $extension): void {
    $path = str_replace('.webp', '.'.$extension, $this->card);

    expect($this->service->serve($path)->status)->toBe(404)->and($this->processor->made)->toBe([]);
})->with(['php', 'svg', 'html', 'gif', 'phtml', 'json', 'txt']);

it('has bunny only name a declared class, and nothing a visitor can add changes what the site links to', function (): void {
    $driver = new BunnyThumbnailDriver($this->config, $this->sizes);
    $url = $driver->url('2026/10/photo.png', $this->sizes->get('card'), 3000, 2000);

    expect($url)->toStartWith('https://media.client.fr/2026/10/photo.png?class=card&v=')
        ->and($driver->sizeOf('https://media.client.fr/2026/10/photo.png?class=banner&v=1'))->toBeNull()
        ->and($driver->sizeOf('https://media.client.fr/2026/10/photo.png?width=99999'))->toBeNull()
        ->and($driver->sizeOf($url))->toBe('card');
});
