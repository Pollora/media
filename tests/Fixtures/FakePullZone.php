<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

use Pollora\Media\Domain\Contracts\BunnyPullZone;

/**
 * A pull zone held in memory: what the exporter reads, and what it writes.
 */
final class FakePullZone implements BunnyPullZone
{
    /** @var list<list<array{Name: string, Properties: array<string, string>}>> */
    public array $saved = [];

    /**
     * @param  list<array{Name: string, Properties: array<string, string>}>  $classes
     */
    public function __construct(public array $classes = []) {}

    public function classes(string $pullZone): array
    {
        return $this->classes;
    }

    public function saveClasses(string $pullZone, array $classes): void
    {
        $this->saved[] = $classes;
        $this->classes = $classes;
    }
}
