<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Infrastructure\WordPress\WordPressBunnyPullZone;

beforeEach(function (): void {
    $this->requests = new ArrayObject;
    Functions\when('is_wp_error')->alias(fn (mixed $value): bool => $value instanceof WP_Error);
    Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $response): int => $response['response']['code']);
    Functions\when('wp_remote_retrieve_body')->alias(fn (array $response): string => $response['body']);
});

/**
 * @param  array<string, mixed>  $response
 */
function answering(array $response, ArrayObject $requests): void
{
    Functions\when('wp_remote_request')->alias(function (string $url, array $args) use ($response, $requests) {
        $requests->append(['url' => $url, 'args' => $args]);

        return $response;
    });
}

it('reads the classes of a pull zone with the access key in the header, and nowhere else', function (): void {
    answering(['response' => ['code' => 200], 'body' => json_encode(['Id' => 1234, 'OptimizerClasses' => [['Name' => 'card', 'Properties' => ['width' => '600']]], 'OptimizerForceClasses' => true])], $this->requests);

    $classes = (new WordPressBunnyPullZone('the-access-key'))->classes('1234');

    expect($classes)->toBe([['Name' => 'card', 'Properties' => ['width' => '600']]])
        ->and($this->requests[0]['url'])->toBe('https://api.bunny.net/pullzone/1234')
        ->and($this->requests[0]['args']['method'])->toBe('GET')
        ->and($this->requests[0]['args']['headers']['AccessKey'])->toBe('the-access-key')
        ->and($this->requests[0]['url'])->not->toContain('the-access-key');
});

it('reads no classes from a pull zone that has none', function (): void {
    answering(['response' => ['code' => 200], 'body' => json_encode(['Id' => 1234, 'OptimizerClasses' => null])], $this->requests);

    expect((new WordPressBunnyPullZone('k'))->classes('1234'))->toBe([]);
});

it('writes the classes alone: it never touches the forced classes', function (): void {
    answering(['response' => ['code' => 200], 'body' => '{}'], $this->requests);

    (new WordPressBunnyPullZone('k'))->saveClasses('1234', [['Name' => 'card', 'Properties' => ['width' => '600']]]);

    $request = $this->requests[0];
    $body = json_decode($request['args']['body'], true);

    expect($request['url'])->toBe('https://api.bunny.net/pullzone/1234')
        ->and($request['args']['method'])->toBe('POST')
        ->and($request['args']['headers']['Content-Type'])->toBe('application/json')
        ->and(array_keys($body))->toBe(['OptimizerClasses'])
        ->and($body['OptimizerClasses'][0])->toBe(['Name' => 'card', 'Properties' => ['width' => '600']]);
});

it('says the status when Bunny refuses, without the key', function (): void {
    answering(['response' => ['code' => 401], 'body' => '{"Message":"Unauthorized"}'], $this->requests);

    try {
        (new WordPressBunnyPullZone('the-access-key'))->classes('1234');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('401')->not->toContain('the-access-key');

        return;
    }

    throw new LogicException('Expected a RuntimeException.');
});

it('refuses a pull zone that is not an id before it builds a URL', function (): void {
    answering(['response' => ['code' => 200], 'body' => '{}'], $this->requests);

    expect(fn () => (new WordPressBunnyPullZone('k'))->classes('12/../34'))->toThrow(InvalidArgumentException::class)
        ->and($this->requests)->toHaveCount(0);
});
