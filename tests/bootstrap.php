<?php

declare(strict_types=1);

/**
 * Bootstrap of the unit suite: Composer's autoloader, then the stand-in for WP-CLI.
 *
 * The stand-in is loaded here and not in Pest.php: Pest loads Pest.php in every run, the integration suite included,
 * and that suite runs a real WordPress, which must not find a `WP_CLI` constant and class that are not WP-CLI.
 */
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Fixtures/WpCli.php';
