<?php

declare(strict_types=1);

use Pollora\Media\Infrastructure\Cast;

it('casts scalars and stringables as PHP does', function (): void {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return '12px';
        }
    };

    expect(Cast::text(12))->toBe('12')
        ->and(Cast::text(null))->toBe('')
        ->and(Cast::text($stringable))->toBe('12px')
        ->and(Cast::integer('12px'))->toBe(12)
        ->and(Cast::integer(true))->toBe(1)
        ->and(Cast::integer($stringable))->toBe(12)
        ->and(Cast::optionalText(null))->toBeNull()
        ->and(Cast::optionalText(5))->toBe('5');
});

it('reads nothing in a value that has no text', function (mixed $value): void {
    expect(Cast::text($value))->toBe('')
        ->and(Cast::integer($value))->toBe(0);
})->with(['an array' => [['a']], 'a plain object' => [new stdClass]]);
