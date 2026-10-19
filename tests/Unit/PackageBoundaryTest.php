<?php

declare(strict_types=1);

// pollora/media runs with or without Laravel: nothing in it may reach Illuminate or the framework.
arch('the package does not use Laravel')
    ->expect('Pollora\Media')
    ->not->toUse(['Illuminate', 'Laravel', 'Pollora\Support', 'Pollora\Models', 'Pollora\Doctor']);

arch('the domain stays free of WordPress and of the storage library')
    ->expect('Pollora\Media\Domain')
    ->not->toUse(['League\Flysystem', 'Pollora\Media\Infrastructure']);
