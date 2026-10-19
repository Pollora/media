<?php

declare(strict_types=1);

use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Services\LegacyUrlRewriter;
use Pollora\Media\Domain\Services\MediaUrl;

it('builds a media URL from the public URL and a relative path, and nothing else', function (string $base, string $path, string $expected): void {
    expect((new MediaUrl(new MediaConfig($base)))->for($path))->toBe($expected);
})->with([
    'plain' => ['https://media.client.fr', '2026/10/a.jpg', 'https://media.client.fr/2026/10/a.jpg'],
    'trailing slash on the base' => ['https://media.client.fr/', '2026/a.jpg', 'https://media.client.fr/2026/a.jpg'],
    'leading slash on the path' => ['https://media.client.fr', '/2026/a.jpg', 'https://media.client.fr/2026/a.jpg'],
    'a base with a path' => ['https://site.test/content/uploads', '2026/a.jpg', 'https://site.test/content/uploads/2026/a.jpg'],
    'a path with spaces is left alone' => ['https://media.client.fr', '2026/a b.jpg', 'https://media.client.fr/2026/a b.jpg'],
]);

it('keeps the settings of a configuration immutable and normalised', function (): void {
    $config = new MediaConfig('https://media.client.fr/', ['https://old.test/content/', 'https://older.test'], 'public, max-age=60', '+2 hours', true);

    expect($config->url)->toBe('https://media.client.fr')
        ->and($config->legacyUrls)->toBe(['https://old.test/content', 'https://older.test'])
        ->and($config->cacheControl)->toBe('public, max-age=60')
        ->and($config->privateExpiry)->toBe('+2 hours')
        ->and($config->asyncThumbnails)->toBeTrue()
        ->and((new ReflectionClass($config))->isReadOnly())->toBeTrue();
});

it('has the defaults of the specification', function (): void {
    $config = new MediaConfig('https://media.client.fr');

    expect($config->legacyUrls)->toBe([])
        ->and($config->cacheControl)->toBe('public, max-age=31536000, immutable')
        ->and($config->privateExpiry)->toBe('+6 hours')
        ->and($config->asyncThumbnails)->toBeFalse();
});

it('rewrites the legacy URL bases to the public URL with a plain replacement, and back to nothing when none is set', function (): void {
    $rewriter = new LegacyUrlRewriter(new MediaConfig('https://media.client.fr', ['https://old.test/content/uploads', 'https://older.test/uploads']));

    $html = '<img src="https://old.test/content/uploads/2026/a.jpg"><a href="https://older.test/uploads/b.pdf">b</a><img src="https://media.client.fr/c.jpg">';

    expect($rewriter->rewrite($html))->toBe('<img src="https://media.client.fr/2026/a.jpg"><a href="https://media.client.fr/b.pdf">b</a><img src="https://media.client.fr/c.jpg">')
        ->and((new LegacyUrlRewriter(new MediaConfig('https://media.client.fr')))->rewrite($html))->toBe($html)
        ->and($rewriter->isActive())->toBeTrue()
        ->and((new LegacyUrlRewriter(new MediaConfig('https://media.client.fr')))->isActive())->toBeFalse();
});
