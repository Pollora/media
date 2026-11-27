<?php

declare(strict_types=1);

use Pollora\Media\Domain\Services\SerializedReplacer;
use Pollora\Media\Tests\Fixtures\SerializedGadget;

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

it('leaves a value that holds an object of a class it does not know untouched, at any depth', function (string $value): void {
    expect((new SerializedReplacer)->replace($value, 'https://old.test', 'https://m.test'))->toBe($value);
})->with([
    'at the root' => ['O:7:"Unknown":1:{s:3:"url";s:20:"https://old.test/a.j";}'],
    'in an array' => ['a:1:{s:1:"k";O:7:"Unknown":1:{s:3:"url";s:20:"https://old.test/a.j";}}'],
    'in an object' => ['O:8:"stdClass":1:{s:1:"k";O:7:"Unknown":1:{s:3:"url";s:20:"https://old.test/a.j";}}'],
]);

it('never wakes an object it does not know: reading a value must not run its code', function (string $value): void {
    SerializedGadget::$woken = false;

    expect((new SerializedReplacer)->replace($value, 'https://old.test', 'https://m.test'))->toBe($value)
        ->and(SerializedGadget::$woken)->toBeFalse();
})->with([
    'at the root' => fn (): string => serialize(new SerializedGadget('https://old.test/a.jpg')),
    'in an array' => fn (): string => serialize(['k' => new SerializedGadget('https://old.test/a.jpg')]),
]);

it('keeps a serialized string, array key and stdClass valid', function (): void {
    $replacer = new SerializedReplacer;

    expect($replacer->replace(serialize('https://old.test/a.jpg'), 'https://old.test', 'https://media.client.fr'))->toBe(serialize('https://media.client.fr/a.jpg'))
        ->and($replacer->replace(serialize(['https://old.test/a.jpg' => 1, 7 => 2]), 'https://old.test', 'https://m.test'))->toBe(serialize(['https://m.test/a.jpg' => 1, 7 => 2]))
        ->and($replacer->replace(serialize((object) ['p' => 'https://old.test']), 'https://old.test', 'https://m.test'))->toBe(serialize((object) ['p' => 'https://m.test']));
});

it('replaces as plain text what only looks serialized', function (string $value, string $expected): void {
    // unserialize() says what it could not read, silenced by the code but not by PHPUnit
    set_error_handler(static fn (): bool => true);

    try {
        expect((new SerializedReplacer)->replace($value, 'old', 'new'))->toBe($expected);
    } finally {
        restore_error_handler();
    }
})->with([
    'text with a colon' => ['a: the old way;', 'a: the new way;'],
    'broken serialized data' => ['s:99:"old";', 's:99:"new";'],
]);

it('leaves a value alone when there is nothing to search for', function (): void {
    expect((new SerializedReplacer)->replace('abc', '', 'x'))->toBe('abc');
});

it('changes nothing of a value that holds an unknown object, not even the strings beside it', function (string $value): void {
    expect((new SerializedReplacer)->replace($value, 'https://old.test', 'https://m.test'))->toBe($value);
})->with([
    'in an array' => fn (): string => serialize(['url' => 'https://old.test/a.jpg', 'gadget' => new SerializedGadget('x')]),
    'deep in a stdClass' => fn (): string => serialize((object) ['url' => 'https://old.test/a.jpg', 'inner' => [(object) ['gadget' => new SerializedGadget('x')]]]),
]);
