<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Hook\Async\PendingAsync;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Hook\Domain\Contract\HookInterface;
use Pollora\Media\Infrastructure\WordPress\AsyncThumbnails;

beforeEach(function (): void {
    $this->thumbnails = new AsyncThumbnails;
    $this->sizes = [
        'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
        'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
        'medium_large' => ['width' => 768, 'height' => 0, 'crop' => false],
        'large' => ['width' => 1024, 'height' => 1024, 'crop' => false],
        'woocommerce_single' => ['width' => 600, 'height' => 0, 'crop' => false],
    ];
});

afterEach(function (): void {
    AsyncThumbnails::generatingEverything(false);
});

it('keeps only the sizes the library and the editor need at once: thumbnail and medium', function (): void {
    expect(array_keys($this->thumbnails->keepEagerSizes($this->sizes)))->toBe(['thumbnail', 'medium']);
});

it('keeps every size while the deferred job generates them', function (): void {
    AsyncThumbnails::generatingEverything(true);

    expect($this->thumbnails->keepEagerSizes($this->sizes))->toBe($this->sizes);
});

it('queues the other sizes after the upload, once, when some are missing', function (): void {
    Functions\when('wp_get_missing_image_subsizes')->justReturn(['large' => [], 'medium_large' => []]);
    Actions\expectDone(AsyncThumbnails::HOOK)->once()->with(42);

    $meta = ['sizes' => ['thumbnail' => []]];

    expect($this->thumbnails->queueTheRest($meta, 42, 'create'))->toBe($meta);
});

it('queues nothing when no size is missing, or when the metadata is an update of the job itself', function (): void {
    Actions\expectDone(AsyncThumbnails::HOOK)->never();

    Functions\when('wp_get_missing_image_subsizes')->justReturn([]);
    $this->thumbnails->queueTheRest(['sizes' => []], 42, 'create');

    Functions\when('wp_get_missing_image_subsizes')->justReturn(['large' => []]);
    $this->thumbnails->queueTheRest(['sizes' => []], 42, 'update');

    expect(true)->toBeTrue();
});

it('generates the missing sizes with every size allowed, then goes back to the restriction', function (): void {
    Functions\expect('wp_update_image_subsizes')->once()->with(42)->andReturnUsing(function (): array {
        expect(AsyncThumbnails::isGeneratingEverything())->toBeTrue();

        return [];
    });

    AsyncThumbnails::generate(42);

    expect(AsyncThumbnails::isGeneratingEverything())->toBeFalse();
});

it('registers its two filters and the deferred handler on the hook service it is given', function (): void {
    Brain\Monkey\Filters\expectAdded('intermediate_image_sizes_advanced')->once();
    Brain\Monkey\Filters\expectAdded('wp_generate_attachment_metadata')->once();
    $pending = new PendingAsync(static function (): void {});
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('add')->once()->with(AsyncThumbnails::HOOK, [AsyncThumbnails::class, 'generate'])->andReturn(Mockery::mock(HookInterface::class));
    $action->shouldReceive('async')->once()->andReturn($pending);

    (new AsyncThumbnails($action))->register();

    expect($pending->attempts())->toBe(3);
});
