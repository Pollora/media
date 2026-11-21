<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Media\Domain\Models\FocalPoint;
use Pollora\Media\Infrastructure\WordPress\FocalPoints;

beforeEach(function (): void {
    $this->stored = [];
    $this->reads = 0;
    Functions\when('get_post_meta')->alias(function (int $id, string $key, bool $single) {
        $this->reads++;

        return $this->stored[$id][$key] ?? '';
    });
    $this->points = new FocalPoints;
});

it('declares the meta of the attachment, for the REST API, with a schema that bounds both coordinates', function (): void {
    $args = null;
    Functions\expect('register_post_meta')->once()->andReturnUsing(function (string $type, string $key, array $arguments) use (&$args): bool {
        $args = [$type, $key, $arguments];

        return true;
    });

    $this->points->register();

    [$type, $key, $arguments] = $args;

    expect($type)->toBe('attachment')
        ->and($key)->toBe('_pollora_focal_point')
        ->and($arguments['single'])->toBeTrue()
        ->and($arguments['type'])->toBe('object')
        ->and($arguments['show_in_rest']['schema']['properties']['x'])->toMatchArray(['type' => 'number', 'minimum' => 0, 'maximum' => 1])
        ->and($arguments['show_in_rest']['schema']['properties']['y'])->toMatchArray(['type' => 'number', 'minimum' => 0, 'maximum' => 1])
        ->and($arguments['show_in_rest']['schema']['required'])->toBe(['x', 'y'])
        ->and($arguments['show_in_rest']['schema']['additionalProperties'])->toBeFalse()
        ->and(($arguments['sanitize_callback'])(['x' => '0.3', 'y' => 1]))->toBe(['x' => 0.3, 'y' => 1.0])
        ->and(($arguments['sanitize_callback'])(['x' => 7, 'y' => 1]))->toBe('');
});

it('lets only a user who can edit the attachment write the meta over REST', function (bool $can): void {
    $callback = null;
    Functions\when('register_post_meta')->alias(function (string $type, string $key, array $arguments) use (&$callback): bool {
        $callback = $arguments['auth_callback'];

        return true;
    });
    Functions\expect('current_user_can')->once()->with('edit_post', 42)->andReturn($can);

    $this->points->register();

    expect($callback(false, '_pollora_focal_point', 42))->toBe($can);
})->with([true, false]);

it('reads the point of an image, typed, and asks the database once per media', function (): void {
    $this->stored[7]['_pollora_focal_point'] = ['x' => 0.25, 'y' => '0.75'];

    $point = $this->points->for(7);

    expect($point)->toBeInstanceOf(FocalPoint::class)
        ->and($point->toArray())->toBe(['x' => 0.25, 'y' => 0.75])
        ->and($this->points->for(7))->toBe($point)
        ->and($this->reads)->toBe(1);
});

it('has no point for an image that has none, or whose meta is malformed', function (mixed $value): void {
    $this->stored[7]['_pollora_focal_point'] = $value;

    expect($this->points->for(7))->toBeNull();
})->with([
    'nothing stored' => [''],
    'out of range' => [['x' => 3, 'y' => 0.5]],
    'a string' => ['0.5,0.5'],
    'a missing axis' => [['y' => 0.5]],
]);

it('gives the last word to the filter, which may set, move or remove the point', function (): void {
    $this->stored[7]['_pollora_focal_point'] = ['x' => 0.25, 'y' => 0.75];
    $this->stored[8] = [];
    Filters\expectApplied('pollora/media/focal_point')->times(3)->andReturnUsing(fn (?array $point, int $id): ?array => match ($id) {
        7 => $point === ['x' => 0.25, 'y' => 0.75] ? ['x' => 0.9, 'y' => 0.1] : null,
        8 => $point === null ? ['x' => 0.5, 'y' => 0.2] : $point,
        default => null,
    });

    expect($this->points->for(7)?->toArray())->toBe(['x' => 0.9, 'y' => 0.1])
        ->and($this->points->for(8)?->toArray())->toBe(['x' => 0.5, 'y' => 0.2])
        ->and($this->points->for(9))->toBeNull();
});

it('ignores a filter that answers with something that is not a point, and keeps the stored one', function (): void {
    $this->stored[7]['_pollora_focal_point'] = ['x' => 0.25, 'y' => 0.75];
    Filters\expectApplied('pollora/media/focal_point')->andReturn(['x' => 4, 'y' => 'left']);

    expect($this->points->for(7)?->toArray())->toBe(['x' => 0.25, 'y' => 0.75]);
});

it('writes a validated point as the meta, and forgets what it knew', function (): void {
    $this->stored[7]['_pollora_focal_point'] = ['x' => 0.1, 'y' => 0.1];
    expect($this->points->for(7)?->x)->toBe(0.1);
    Functions\expect('update_post_meta')->once()->with(7, '_pollora_focal_point', ['x' => 0.3, 'y' => 0.7])->andReturnUsing(function (): bool {
        $this->stored[7]['_pollora_focal_point'] = ['x' => 0.3, 'y' => 0.7];

        return true;
    });

    $this->points->set(7, 0.1 + 0.2, 0.7);

    expect($this->points->for(7)?->toArray())->toBe(['x' => 0.3, 'y' => 0.7]);
});

it('refuses to write a point out of range, and writes nothing', function (): void {
    Functions\expect('update_post_meta')->never();

    expect(fn () => $this->points->set(7, 1.2, 0.5))->toThrow(InvalidArgumentException::class);
});

it('removes the meta to clear the point', function (): void {
    $this->stored[7]['_pollora_focal_point'] = ['x' => 0.1, 'y' => 0.1];
    expect($this->points->for(7))->not->toBeNull();
    Functions\expect('delete_post_meta')->once()->with(7, '_pollora_focal_point')->andReturnUsing(function (): bool {
        unset($this->stored[7]);

        return true;
    });

    $this->points->clear(7);

    expect($this->points->for(7))->toBeNull();
});

it('lists the point of each original by its path, once', function (): void {
    $this->stored[7] = ['_pollora_focal_point' => ['x' => 0.25, 'y' => 0.75], '_wp_attached_file' => '2026/10/photo.jpg'];
    $this->stored[8] = ['_pollora_focal_point' => ['x' => 0.5, 'y' => 0.5], '_wp_attached_file' => ''];
    $this->stored[9] = ['_pollora_focal_point' => 'garbage', '_wp_attached_file' => '2026/10/other.jpg'];
    Functions\expect('get_posts')->once()->andReturn([7, 8, 9]);

    $byFile = $this->points->byFile();

    expect(array_keys($byFile))->toBe(['2026/10/photo.jpg'])
        ->and($byFile['2026/10/photo.jpg']->toArray())->toBe(['x' => 0.25, 'y' => 0.75])
        ->and($this->points->byFile())->toBe($byFile);
});
