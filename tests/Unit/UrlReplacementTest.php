<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Media\Infrastructure\WordPress\UrlReplacement;
use Pollora\Media\Tests\Fixtures\FakeDatabase;

beforeEach(function (): void {
    $this->flushed = 0;
    Functions\when('wp_cache_flush')->alias(function (): bool {
        $this->flushed++;

        return true;
    });
    $this->old = 'https://site.test/wp-content/uploads';
    $this->new = 'https://media.client.fr';
    $this->database = new FakeDatabase([
        'wp_posts' => [
            1 => ['post_content' => '<img src="'.$this->old.'/a.jpg">', 'post_excerpt' => 'nothing here'],
            2 => ['post_content' => '<!-- wp:image {"url":"'.str_replace('/', '\\/', $this->old).'\\/b.jpg"} -->', 'post_excerpt' => ''],
        ],
        'wp_postmeta' => [
            10 => ['meta_value' => serialize(['url' => $this->old.'/c.jpg', 'n' => 3])],
        ],
        'wp_options' => [
            20 => ['option_value' => $this->old.'/logo.png'],
        ],
    ]);
});

it('counts what it would change without writing anything in a dry run', function (): void {
    $changed = (new UrlReplacement($this->database))->replace($this->old, $this->new);

    expect($changed)->toBe([
        'posts.post_content' => 2,
        'posts.post_excerpt' => 0,
        'postmeta.meta_value' => 1,
        'termmeta.meta_value' => 0,
        'usermeta.meta_value' => 0,
        'options.option_value' => 1,
    ])->and($this->database->updates)->toBe([]);
});

it('rewrites the plain, the escaped and the serialized forms of the address when it is executed', function (): void {

    (new UrlReplacement($this->database))->replace($this->old, $this->new, dryRun: false);

    $tables = $this->database->tables;
    expect($tables['wp_posts'][1]['post_content'])->toBe('<img src="https://media.client.fr/a.jpg">')
        ->and($tables['wp_posts'][2]['post_content'])->toBe('<!-- wp:image {"url":"https:\/\/media.client.fr\/b.jpg"} -->')
        ->and(unserialize($tables['wp_postmeta'][10]['meta_value']))->toBe(['url' => 'https://media.client.fr/c.jpg', 'n' => 3])
        ->and($tables['wp_options'][20]['option_value'])->toBe('https://media.client.fr/logo.png')
        ->and($this->database->updates)->toHaveCount(4)
        ->and($this->flushed)->toBe(1);
});

it('leaves a row alone when the address is not in it', function (): void {
    $changed = (new UrlReplacement($this->database))->replace('https://other.test/uploads', $this->new, dryRun: false);

    expect(array_sum($changed))->toBe(0)
        ->and($this->database->updates)->toBe([]);
});
