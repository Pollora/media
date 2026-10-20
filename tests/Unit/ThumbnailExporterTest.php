<?php

declare(strict_types=1);

use Pollora\Media\Application\Services\ImageSizes;
use Pollora\Media\Application\Services\ThumbnailExporter;
use Pollora\Media\Application\Services\ThumbnailLimits;
use Pollora\Media\Domain\Enums\Anchor;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;
use Pollora\Media\Tests\Fixtures\FakePullZone;

beforeEach(function (): void {
    $this->sizes = new ImageSizes(fn (): string => 'secret-key');
    $this->sizes->add('card')->cover(600, 400)->webp(75);
    $this->sizes->add('hero')->scale(width: 1920)->widths([640, 1024]);
    $this->pullZone = new FakePullZone;
    $this->settings = new ThumbnailDriverSettings(
        driver: 'bunny',
        bunnyPullZone: '1234',
        bunnyApiKey: 'super-secret-access-key',
        imgproxyUrl: 'https://img.client.fr',
        imgproxyKey: '736563726574',
        imgproxySalt: '68656C6C6F',
    );
    $this->exporter = fn (?ThumbnailDriverSettings $settings = null, ?FakePullZone $pullZone = null): ThumbnailExporter => new ThumbnailExporter(
        $this->sizes,
        $settings ?? $this->settings,
        new ThumbnailLimits,
        new MediaConfig('https://media.client.fr'),
        $pullZone ?? $this->pullZone,
    );
});

it('shows what a Bunny export would create, and writes nothing without --execute', function (): void {
    $report = ($this->exporter)()->export('bunny', execute: false);

    expect($report->created)->toBe(['card', 'hero', 'hero640w', 'hero1024w'])
        ->and($report->applied)->toBeFalse()
        ->and($this->pullZone->saved)->toBe([])
        ->and(implode("\n", $report->lines))->toContain('+ card');
});

it('writes the classes with --execute, one per size and per extra width', function (): void {
    $report = ($this->exporter)()->export('bunny', execute: true);

    expect($report->applied)->toBeTrue()
        ->and($this->pullZone->saved)->toHaveCount(1)
        ->and(array_column($this->pullZone->saved[0], 'Name'))->toBe(['card', 'hero', 'hero640w', 'hero1024w'])
        ->and($this->pullZone->saved[0][0]['Properties'])->toBe(['aspect_ratio' => '3:2', 'format' => 'webp', 'quality' => '75', 'width' => '600'])
        ->and($this->pullZone->saved[0][3]['Properties'])->toBe(['format' => 'webp', 'quality' => '80', 'width' => '1024']);
});

it('keeps the classes the client made, updates the ones that differ, and leaves the same ones alone', function (): void {
    $this->pullZone->classes = [
        ['Name' => 'client-banner', 'Properties' => ['width' => '1200']],
        ['Name' => 'card', 'Properties' => ['width' => '100']],
        ['Name' => 'hero', 'Properties' => ['width' => '1920', 'quality' => '80', 'format' => 'webp']],
    ];

    $report = ($this->exporter)()->export('bunny', execute: true);

    expect($report->updated)->toBe(['card'])
        ->and($report->unchanged)->toBe(['hero'])
        ->and($report->created)->toBe(['hero640w', 'hero1024w'])
        ->and(array_column($this->pullZone->saved[0], 'Name'))->toBe(['client-banner', 'card', 'hero', 'hero640w', 'hero1024w']);
});

it('does not write when nothing changes', function (): void {
    ($this->exporter)()->export('bunny', execute: true);
    $this->pullZone->saved = [];

    $report = ($this->exporter)()->export('bunny', execute: true);

    expect($report->applied)->toBeFalse()->and($this->pullZone->saved)->toBe([])->and($report->unchanged)->toHaveCount(4);
});

it('warns about what Bunny does not guarantee, and says nothing of a size it handles', function (): void {
    expect(($this->exporter)()->export('bunny', execute: false)->warnings)->toBe([]);

    $this->sizes->add('box')->contain(800, 800)->avif(60);

    expect(($this->exporter)()->export('bunny', execute: false)->warnings[0])->toContain('"box"')->toContain('AVIF');
});

it('leaves an anchored cover out of a Bunny export and says why', function (): void {
    $this->sizes->add('portrait')->cover(400, 500, Anchor::Top);

    $bunny = ($this->exporter)()->export('bunny', execute: false);

    expect($bunny->created)->not->toContain('portrait')->and($bunny->warnings[0])->toContain('"portrait"')->toContain('anchored to the top');
});

it('needs the pull zone and the key for Bunny, and never prints the key', function (): void {
    $without = new ThumbnailDriverSettings(driver: 'bunny');

    expect(fn () => ($this->exporter)($without)->export('bunny', execute: false))->toThrow(InvalidArgumentException::class, 'POLLORA_MEDIA_BUNNY_PULL_ZONE');

    $report = ($this->exporter)()->export('bunny', execute: true);

    expect(json_encode($report))->not->toContain('super-secret-access-key');
});

it('prints the imgproxy configuration, presets-only, without the key or the salt', function (): void {
    $report = ($this->exporter)()->export('imgproxy', execute: false);
    $output = implode("\n", $report->lines);

    expect($output)->toContain('IMGPROXY_PRESETS=card=resize:fill-down:600:400:0/quality:75,hero=resize:fit:1920:0:0/quality:80,hero-640w=resize:fit:640:0:0/quality:80,hero-1024w=resize:fit:1024:0:0/quality:80')
        ->and($output)->toContain('IMGPROXY_ONLY_PRESETS=true')
        ->and($output)->toContain('IMGPROXY_MAX_SRC_RESOLUTION=50')
        ->and($output)->toContain('IMGPROXY_ALLOWED_SOURCES=https://media.client.fr/')
        ->and($output)->not->toContain('IMGPROXY_SIGNATURE_SIZE')
        ->and($output)->not->toContain('736563726574')
        ->and($output)->not->toContain('68656C6C6F')
        ->and($report->applied)->toBeFalse();
});

it('prints the signature size when it is not the default, and the bucket as the allowed source for s3', function (): void {
    $settings = new ThumbnailDriverSettings(driver: 'imgproxy', imgproxyUrl: 'https://img.client.fr', imgproxyKey: '73', imgproxySalt: '68', imgproxySignatureSize: 16, imgproxySource: 's3', imgproxyBucket: 'client-media');
    $output = implode("\n", ($this->exporter)($settings)->export('imgproxy', execute: false)->lines);

    expect($output)->toContain('IMGPROXY_SIGNATURE_SIZE=16')->and($output)->toContain('IMGPROXY_ALLOWED_SOURCES=s3://client-media/');
});

it('leaves out a size imgproxy cannot serve, and says so', function (): void {
    $this->sizes->add('default')->scale(width: 100);

    $report = ($this->exporter)()->export('imgproxy', execute: false);

    expect(implode("\n", $report->lines))->not->toContain('default=')
        ->and(implode(' ', $report->warnings))->toContain('default');
});

it('exports nothing for the default driver, and refuses an unknown one', function (): void {
    expect(fn () => ($this->exporter)()->export('pollora', execute: false))->toThrow(InvalidArgumentException::class, 'nothing to export')
        ->and(fn () => ($this->exporter)()->export('cloudfront', execute: false))->toThrow(InvalidArgumentException::class, 'cloudfront');
});

it('knows the configured driver, which the command takes by default', function (): void {
    expect(($this->exporter)()->configuredDriver())->toBe('bunny');
});

it('warns that imgproxy refuses a loopback source by default, for a media URL on this machine', function (string $url, bool $warns): void {
    $exporter = new ThumbnailExporter($this->sizes, $this->settings, new ThumbnailLimits, new MediaConfig($url), $this->pullZone);

    $warnings = implode(' ', $exporter->export('imgproxy', execute: false)->warnings);

    expect(str_contains($warnings, 'IMGPROXY_ALLOW_LOOPBACK_SOURCE_ADDRESSES'))->toBe($warns);
})->with([
    'localhost' => ['http://localhost:8080/uploads', true],
    'loopback ip' => ['http://127.0.0.1:18081', true],
    'ipv6 loopback' => ['http://[::1]/uploads', true],
    'a real domain' => ['https://media.client.fr', false],
    'a name that only starts like one' => ['https://localhost.client.fr', false],
]);

it('names the Bunny classes with letters and digits only, and refuses the second of two sizes that collide', function (): void {
    $this->sizes->add('my_size')->scale(width: 100);
    $this->sizes->add('mysize')->scale(width: 200);

    $report = ($this->exporter)()->export('bunny', execute: true);

    expect($report->created)->toContain('mysize')
        ->and(array_count_values(array_column($this->pullZone->saved[0], 'Name'))['mysize'])->toBe(1)
        ->and($this->pullZone->saved[0][4]['Properties'])->toMatchArray(['width' => '100'])
        ->and(implode(' ', $report->warnings))->toContain('"mysize" would get the Bunny class');
});
