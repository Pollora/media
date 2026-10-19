<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Application\Services\ThumbnailResult;
use Pollora\Media\Application\Services\ThumbnailService;
use Pollora\Media\Domain\Models\MediaConfig;

/**
 * The fallback for a size that does not exist yet, for WordPress without Laravel: the web server sends
 * a request for a missing file to WordPress, and this answers on `parse_request`, before WordPress
 * has run its query. In a Pollora application, a Laravel route does the same.
 */
final readonly class ThumbnailRoute
{
    private const string SIZED = '/-[a-z0-9_]+(?:-\d+w)?-\d+x\d+-[0-9a-f]{12}\.(?:webp|avif|jpe?g|png)$/i';

    public function __construct(
        private ThumbnailService $service,
        private MediaConfig $config,
    ) {}

    public function register(): void
    {
        \add_action('parse_request', [$this, 'handle'], 0);
    }

    /**
     * Answers and ends the request when the path is a size the module makes; otherwise lets WordPress go on.
     */
    public function handle(): void
    {
        $result = $this->resolve((string) ($_SERVER['REQUEST_URI'] ?? ''));

        if (! $result instanceof ThumbnailResult || $result->status !== 200 || ! is_resource($result->body)) {
            return;
        }

        \status_header(200);
        header('Content-Type: '.$result->contentType);

        foreach ($result->headers as $name => $value) {
            header($name.': '.$value);
        }

        fpassthru($result->body);
        exit;
    }

    /**
     * @return ThumbnailResult|null Null when the path is not the module's business
     */
    public function resolve(string $path): ?ThumbnailResult
    {
        $key = $this->keyOf($path);

        return $key === null ? null : $this->service->serve($key);
    }

    /**
     * The path of the library a request is for, or null when it is not for a size.
     */
    public function keyOf(string $requestPath): ?string
    {
        $path = rawurldecode((string) strtok($requestPath, '?'));

        if ($path === '' || preg_match(self::SIZED, $path) !== 1) {
            return null;
        }

        $prefix = rtrim((string) parse_url($this->config->url, PHP_URL_PATH), '/');

        return ltrim($prefix !== '' && str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix)) : $path, '/');
    }
}
