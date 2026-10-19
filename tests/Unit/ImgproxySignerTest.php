<?php

declare(strict_types=1);

use Pollora\Media\Domain\Services\ImgproxySigner;

/*
 * The vector is the worked example of the imgproxy documentation (signing a URL, 4.0.x, read on 2026-10-19):
 * key "secret", salt "hello", both given in hexadecimal.
 */
const IMGPROXY_DOC_PATH = '/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png';

it('signs the path the way the imgproxy documentation does', function (): void {
    $signer = new ImgproxySigner('736563726574', '68656C6C6F');

    expect($signer->sign(IMGPROXY_DOC_PATH))->toBe('oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8');
});

it('rebuilds the complete signed URL of the documentation', function (): void {
    $signer = new ImgproxySigner('736563726574', '68656C6C6F');

    expect('http://imgproxy.example.com/'.$signer->sign(IMGPROXY_DOC_PATH).IMGPROXY_DOC_PATH)
        ->toBe('http://imgproxy.example.com/oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png');
});

it('changes with the key, the salt and every character of the path', function (): void {
    $reference = (new ImgproxySigner('736563726574', '68656C6C6F'))->sign('/card/plain/https://m.test/a.jpg@webp');

    expect((new ImgproxySigner('736563726575', '68656C6C6F'))->sign('/card/plain/https://m.test/a.jpg@webp'))->not->toBe($reference)
        ->and((new ImgproxySigner('736563726574', '68656C6C70'))->sign('/card/plain/https://m.test/a.jpg@webp'))->not->toBe($reference)
        ->and((new ImgproxySigner('736563726574', '68656C6C6F'))->sign('/card/plain/https://m.test/a.jpg@avif'))->not->toBe($reference);
});

it('is url-safe, without padding, and as long as the signature size says', function (): void {
    $full = (new ImgproxySigner('736563726574', '68656C6C6F'))->sign(IMGPROXY_DOC_PATH);
    $short = (new ImgproxySigner('736563726574', '68656C6C6F', 8))->sign(IMGPROXY_DOC_PATH);

    expect($full)->toHaveLength(43)->and($full)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($short)->toHaveLength(11)
        ->and(str_starts_with($full, substr($short, 0, 10)))->toBeTrue();
});

it('refuses a key or a salt that is not hexadecimal, and a size imgproxy cannot use', function (string $key, string $salt, int $size): void {
    new ImgproxySigner($key, $salt, $size);
})->with([
    'key not hex' => ['secret', '68656C6C6F', 32],
    'salt not hex' => ['736563726574', 'hello', 32],
    'odd length' => ['73656372657', '68656C6C6F', 32],
    'empty key' => ['', '68656C6C6F', 32],
    'size zero' => ['736563726574', '68656C6C6F', 0],
    'size over 32' => ['736563726574', '68656C6C6F', 33],
])->throws(InvalidArgumentException::class);
