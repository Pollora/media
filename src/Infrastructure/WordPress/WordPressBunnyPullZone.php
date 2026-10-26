<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use InvalidArgumentException;
use Pollora\Media\Domain\Contracts\BunnyPullZone;
use Pollora\Media\Domain\Exceptions\BunnyRequestFailed;
use SensitiveParameter;

/**
 * The pull zone API of Bunny, called with the HTTP API of WordPress. The access key is sent in the
 * `AccessKey` header and nowhere else: not in a URL, not in a message.
 *
 * @see https://bunny.net/docs/reference/pullzonepublic_updatepullzone
 */
final readonly class WordPressBunnyPullZone implements BunnyPullZone
{
    private const string ENDPOINT = 'https://api.bunny.net/pullzone/';

    public function __construct(#[SensitiveParameter] private string $accessKey) {}

    public function classes(string $pullZone): array
    {
        $body = $this->request('GET', $pullZone);
        $classes = [];

        foreach (is_array($body['OptimizerClasses'] ?? null) ? $body['OptimizerClasses'] : [] as $class) {
            if (is_array($class) && is_string($class['Name'] ?? null)) {
                $classes[] = ['Name' => $class['Name'], 'Properties' => array_map('strval', (array) ($class['Properties'] ?? []))];
            }
        }

        return $classes;
    }

    public function saveClasses(string $pullZone, array $classes): void
    {
        $this->request('POST', $pullZone, ['OptimizerClasses' => array_map(
            static fn (array $class): array => ['Name' => $class['Name'], 'Properties' => (object) $class['Properties']],
            $classes,
        )]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $pullZone, ?array $payload = null): array
    {
        if (preg_match('/^\d+$/', $pullZone) !== 1) {
            throw new InvalidArgumentException('The id of a Bunny pull zone is a number.');
        }

        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => ['AccessKey' => $this->accessKey, 'Accept' => 'application/json'],
        ];

        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        $response = \wp_remote_request(self::ENDPOINT.$pullZone, $args);

        if (\is_wp_error($response)) {
            throw BunnyRequestFailed::unreachable($response->get_error_message());
        }

        $status = (int) \wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            throw BunnyRequestFailed::refused($status, $method, $pullZone);
        }

        $decoded = json_decode((string) \wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : [];
    }
}
