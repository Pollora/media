<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use Pollora\Media\Domain\Models\MediaConfig;

/**
 * Replaces the old base URLs of the library with the current one in a piece
 * of content, during a transition. A plain string replacement: no pattern, no
 * call to the disk, and removing the setting undoes it.
 */
final readonly class LegacyUrlRewriter
{
    public function __construct(private MediaConfig $config) {}

    public function isActive(): bool
    {
        return $this->config->legacyUrls !== [];
    }

    public function rewrite(string $content): string
    {
        if ($this->config->legacyUrls === []) {
            return $content;
        }

        return str_replace($this->config->legacyUrls, $this->config->url, $content);
    }
}
