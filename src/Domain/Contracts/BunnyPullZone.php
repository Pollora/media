<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Contracts;

/**
 * The image classes of a Bunny pull zone, as the API of the pull zone gives them: there is no API
 * of classes alone, they are a field of the pull zone.
 */
interface BunnyPullZone
{
    /**
     * @return list<array{Name: string, Properties: array<string, string>}>
     *
     * @throws \RuntimeException When Bunny cannot be reached or refuses
     */
    public function classes(string $pullZone): array;

    /**
     * Writes the classes, and only the classes: the forced classes setting is never touched.
     *
     * @param  list<array{Name: string, Properties: array<string, string>}>  $classes  The whole list the pull zone must have
     *
     * @throws \RuntimeException When Bunny cannot be reached or refuses
     */
    public function saveClasses(string $pullZone, array $classes): void;
}
