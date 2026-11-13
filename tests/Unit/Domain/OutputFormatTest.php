<?php

declare(strict_types=1);

use Pollora\Media\Domain\Enums\OutputFormat;

it('gives every format the same default quality', function (OutputFormat $format): void {
    expect($format->defaultQuality())->toBe(80);
})->with(OutputFormat::cases());

it('is written the way the declarations name it', function (): void {
    expect(array_column(OutputFormat::cases(), 'value'))->toBe(['webp', 'avif', 'original']);
});
