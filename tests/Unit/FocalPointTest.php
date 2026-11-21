<?php

declare(strict_types=1);

use Pollora\Media\Domain\Models\FocalPoint;

it('is a point of the image, as fractions from 0 to 1 on both axes', function (float $x, float $y): void {
    $point = new FocalPoint($x, $y);

    expect($point->x)->toBe($x)->and($point->y)->toBe($y);
})->with([
    'the centre' => [0.5, 0.5],
    'the top-left corner' => [0.0, 0.0],
    'the bottom-right corner' => [1.0, 1.0],
    'off-centre' => [0.25, 0.75],
]);

it('refuses a coordinate that is not a number from 0 to 1', function (float $x, float $y): void {
    expect(fn () => new FocalPoint($x, $y))->toThrow(InvalidArgumentException::class);
})->with([
    'negative x' => [-0.1, 0.5],
    'x above 1' => [1.01, 0.5],
    'negative y' => [0.5, -0.001],
    'y above 1' => [0.5, 2.0],
    'not a number' => [NAN, 0.5],
    'infinite' => [0.5, INF],
]);

it('keeps a thousandth of the image, so a float that went round a database or a JSON payload is the same point', function (): void {
    $computed = new FocalPoint(0.1 + 0.2, 0.7);
    $read = new FocalPoint(0.3, 0.7);

    expect($computed->x)->toBe(0.3)
        ->and($computed->fingerprint())->toBe($read->fingerprint())
        ->and($computed->token())->toBe($read->token())
        ->and($computed->equals($read))->toBeTrue()
        ->and((new FocalPoint(0.2504, 0.5))->x)->toBe(0.25);
});

it('writes itself in thousandths for a file name, and is read back from them', function (): void {
    $point = new FocalPoint(0.25, 0.75);

    expect($point->token())->toBe('f0250_0750')
        ->and((new FocalPoint(1, 0))->token())->toBe('f1000_0000')
        ->and(FocalPoint::fromThousandths(250, 750)?->equals($point))->toBeTrue()
        ->and(FocalPoint::fromThousandths(1001, 0))->toBeNull()
        ->and(FocalPoint::fromThousandths(0, -1))->toBeNull();
});

it('tells a point from a stored value, and a malformed value is no point', function (mixed $value, ?array $expected): void {
    expect(FocalPoint::tryFrom($value)?->toArray())->toBe($expected);
})->with([
    'a point' => [['x' => 0.25, 'y' => 0.75], ['x' => 0.25, 'y' => 0.75]],
    'numeric strings, as REST or a form sends them' => [['x' => '0.25', 'y' => '0.75'], ['x' => 0.25, 'y' => 0.75]],
    'integers' => [['x' => 1, 'y' => 0], ['x' => 1.0, 'y' => 0.0]],
    'an empty string, the meta of a cleared point' => ['', null],
    'null' => [null, null],
    'a missing axis' => [['x' => 0.5], null],
    'out of range' => [['x' => 1.5, 'y' => 0.5], null],
    'not numbers' => [['x' => 'left', 'y' => 'top'], null],
    'a list' => [[0.5, 0.5], null],
]);

it('is the same point only when both coordinates are', function (): void {
    $point = new FocalPoint(0.25, 0.75);

    expect($point->equals(new FocalPoint(0.25, 0.75)))->toBeTrue()
        ->and($point->equals(new FocalPoint(0.25, 0.76)))->toBeFalse()
        ->and($point->equals(null))->toBeFalse();
});
