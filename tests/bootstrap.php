<?php

declare(strict_types=1);

use Pest\Mutate\Support\StreamWrapper;

/**
 * Bootstrap of the unit suite: Composer's autoloader, then the stand-in for WP-CLI.
 *
 * The stand-in is loaded here and not in Pest.php: Pest loads Pest.php in every run, the integration suite included,
 * and that suite runs a real WordPress, which must not find a `WP_CLI` constant and class that are not WP-CLI.
 */
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Fixtures/WpCli.php';

/*
 * The process of a mutant (`pest --mutate`): Pest serves the mutated source through a stream wrapper on `file`. That
 * wrapper takes every `flock()` as shared and reports a file nobody may read as absent, so tests fail whatever the
 * mutant changes, and Pest counts the mutant as caught. In such a process only, `file` is given back to PHP and the
 * mutated source is declared here, before any test, so the autoloader never reads the original.
 *
 * The mutant is included from the file Pest wrote: `__DIR__` and `__FILE__` would point there. No source file uses
 * them; one that does needs them rewritten here. An original that is already included cannot be replaced, and is left
 * alone: its mutants survive, which the score shows.
 */
$mutatedSource = getenv('PEST_MUTATION_TESTING');

if (is_string($mutatedSource) && class_exists(StreamWrapper::class)) {
    StreamWrapper::disable();

    if (! in_array(realpath($mutatedSource), get_included_files(), true)) {
        require (string) getenv('PEST_MUTATION_FILE');
    }
}
