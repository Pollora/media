<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

/**
 * What the route answers: a status, and for a 200 the bytes and how to cache them.
 */
final readonly class ThumbnailResult
{
    /**
     * @param  resource|null  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public mixed $body = null,
        public string $contentType = '',
        public array $headers = [],
    ) {}

    public static function notFound(): self
    {
        return new self(404);
    }
}
