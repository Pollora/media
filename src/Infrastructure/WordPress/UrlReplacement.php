<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use Pollora\Media\Domain\Services\SerializedReplacer;
use Pollora\Media\Infrastructure\Cast;
use stdClass;

/**
 * Replaces the base URL of the media library in the database after a
 * migration: the content and excerpt of posts, and the values of post meta,
 * term meta, user meta and options, serialized values included. The `guid`
 * is left alone, as WordPress recommends.
 *
 * It only reads in a dry run, and counts what it would change.
 */
final class UrlReplacement
{
    private const int BATCH = 500;

    /** @var list<array{table: string, id: string, column: string}> */
    private const array TARGETS = [
        ['table' => 'posts', 'id' => 'ID', 'column' => 'post_content'],
        ['table' => 'posts', 'id' => 'ID', 'column' => 'post_excerpt'],
        ['table' => 'postmeta', 'id' => 'meta_id', 'column' => 'meta_value'],
        ['table' => 'termmeta', 'id' => 'meta_id', 'column' => 'meta_value'],
        ['table' => 'usermeta', 'id' => 'umeta_id', 'column' => 'meta_value'],
        ['table' => 'options', 'id' => 'option_id', 'column' => 'option_value'],
    ];

    private readonly SerializedReplacer $replacer;

    /**
     * @param  \wpdb  $database  WordPress's `$wpdb`
     */
    public function __construct(private readonly object $database)
    {
        $this->replacer = new SerializedReplacer;
    }

    /**
     * @return array<string, int> Rows changed (or that would be) by `table.column`
     */
    public function replace(string $search, string $replace, bool $dryRun = true): array
    {
        // Block attributes store the URL as JSON, with escaped slashes: both forms are the same address.
        $changed = $this->pass($search, $replace, $dryRun);

        foreach ($this->pass(str_replace('/', '\\/', $search), str_replace('/', '\\/', $replace), $dryRun) as $where => $count) {
            $changed[$where] += $count;
        }

        if (! $dryRun && function_exists('wp_cache_flush')) {
            \wp_cache_flush();
        }

        return $changed;
    }

    /**
     * @return array<string, int>
     */
    private function pass(string $search, string $replace, bool $dryRun): array
    {
        $changed = [];

        foreach (self::TARGETS as ['table' => $table, 'id' => $id, 'column' => $column]) {
            $name = $this->database->{$table};
            $count = 0;
            $last = 0;
            $like = '%'.$this->database->esc_like($search).'%';

            while (true) {
                $rows = $this->database->get_results($this->database->prepare(
                    'SELECT %i AS id, %i AS value FROM %i WHERE %i > %d AND %i LIKE %s ORDER BY %i ASC LIMIT %d',
                    $id,
                    $column,
                    $name,
                    $id,
                    $last,
                    $column,
                    $like,
                    $id,
                    self::BATCH,
                ));

                $rows = array_filter((array) $rows, static fn (mixed $row): bool => $row instanceof stdClass);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $last = Cast::integer($row->id);
                    $new = $this->replacer->replace(Cast::text($row->value), $search, $replace);

                    if ($new === $row->value) {
                        continue;
                    }

                    $count++;

                    if (! $dryRun) {
                        $this->database->update($name, [$column => $new], [$id => $row->id]);
                    }
                }
            }

            $changed[$table.'.'.$column] = $count;
        }

        return $changed;
    }
}
