<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

/**
 * What a `thumbs export` found, and did.
 */
final readonly class ExportReport
{
    /**
     * @param  list<string>  $lines  What to show, in order; for imgproxy, the configuration itself
     * @param  list<string>  $warnings
     * @param  list<string>  $created  Bunny classes that did not exist
     * @param  list<string>  $updated  Bunny classes whose parameters differ
     * @param  list<string>  $unchanged  Bunny classes already as declared
     * @param  bool  $applied  Whether something was written to the service
     */
    public function __construct(
        public string $driver,
        public array $lines,
        public array $warnings = [],
        public array $created = [],
        public array $updated = [],
        public array $unchanged = [],
        public bool $applied = false,
    ) {}
}
