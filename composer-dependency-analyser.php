<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // league/flysystem installs league/flysystem-local, the adapter of the "local" driver: they are released together.
    ->ignoreErrorsOnPackage('league/flysystem-local', [ErrorType::SHADOW_DEPENDENCY])
    // psr/log is part of the package's requirements for the loggers it is given; no class of it is named in src/ yet.
    ->ignoreErrorsOnPackage('psr/log', [ErrorType::UNUSED_DEPENDENCY]);
