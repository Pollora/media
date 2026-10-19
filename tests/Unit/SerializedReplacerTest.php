<?php

declare(strict_types=1);

use Pollora\Media\Domain\Services\SerializedReplacer;

it('replaces in plain strings', function (): void {
    expect((new SerializedReplacer)->replace('<img src="https://old.test/u/a.jpg">', 'https://old.test/u', 'https://m.test'))->toBe('<img src="https://m.test/a.jpg">');
});

it('keeps a serialized value valid when the length of the string changes', function (): void {
    $value = serialize(['url' => 'https://old.test/u/a.jpg', 'n' => 3, 'nested' => ['x' => 'https://old.test/u/b.jpg', 'o' => (object) ['p' => 'https://old.test/u/c.jpg']]]);

    $replaced = (new SerializedReplacer)->replace($value, 'https://old.test/u', 'https://media.client.fr');
    $data = unserialize($replaced);

    expect($data['url'])->toBe('https://media.client.fr/a.jpg')
        ->and($data['n'])->toBe(3)
        ->and($data['nested']['x'])->toBe('https://media.client.fr/b.jpg')
        ->and($data['nested']['o']->p)->toBe('https://media.client.fr/c.jpg');
});

it('replaces inside JSON the way blocks store attributes, as text', function (): void {
    $json = '{"id":5,"url":"https:\/\/old.test\/u\/a.jpg"}';

    expect((new SerializedReplacer)->replace($json, 'https://old.test/u', 'https://m.test'))->toBe($json)
        ->and((new SerializedReplacer)->replace($json, 'https:\/\/old.test\/u', 'https:\/\/m.test'))->toBe('{"id":5,"url":"https:\/\/m.test\/a.jpg"}');
});

it('leaves a value without the string alone, byte for byte', function (): void {
    $value = serialize(['a' => 'b']);

    expect((new SerializedReplacer)->replace($value, 'https://old.test', 'https://m.test'))->toBe($value)
        ->and((new SerializedReplacer)->replace('s:3:"abc";garbage', 'zzz', 'y'))->toBe('s:3:"abc";garbage');
});
