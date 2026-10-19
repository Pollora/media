<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

/**
 * What keeps a request for a size that does not exist yet from costing the server too much.
 */
final readonly class ThumbnailLimits
{
    /**
     * @param  int  $maxPixels  An original above it gets no size (imgproxy's default; covers current cameras)
     * @param  int  $maxBytes  Same, for the weight of the original
     * @param  int  $concurrency  Sizes made at the same time on a server
     * @param  int  $wait  Seconds a request waits for a place, then gets a size that exists
     */
    public function __construct(
        public int $maxPixels = 50_000_000,
        public int $maxBytes = 30 * 1024 * 1024,
        public int $concurrency = 4,
        public int $wait = 10,
    ) {}
}
