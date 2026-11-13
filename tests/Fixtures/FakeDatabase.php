<?php

declare(strict_types=1);

namespace Pollora\Media\Tests\Fixtures;

/**
 * The part of `$wpdb` that the URL replacement uses, over tables held in memory.
 * `prepare()` hands back the query and its values, which `get_results()` reads.
 */
final class FakeDatabase
{
    public string $posts = 'wp_posts';

    public string $postmeta = 'wp_postmeta';

    public string $termmeta = 'wp_termmeta';

    public string $usermeta = 'wp_usermeta';

    public string $options = 'wp_options';

    /** @var list<array{table: string, id: mixed, data: array<string, mixed>}> */
    public array $updates = [];

    /**
     * @param  array<string, array<int, array<string, string>>>  $tables  By table name, then row id, then column
     */
    public function __construct(public array $tables = []) {}

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * @return array{query: string, values: array<int, mixed>}
     */
    public function prepare(string $query, mixed ...$values): array
    {
        return ['query' => $query, 'values' => $values];
    }

    /**
     * @param  array{query: string, values: array<int, mixed>}  $prepared
     * @return list<object>
     */
    public function get_results(array $prepared): array
    {
        preg_match('/SELECT \w+ AS id, (\w+) AS value FROM (\w+) /', $prepared['query'], $found);
        [$last, $like, $limit] = $prepared['values'];
        $needle = stripslashes(trim((string) $like, '%'));
        $rows = [];

        foreach ($this->tables[$found[2]] ?? [] as $id => $columns) {
            if ($id > $last && str_contains($columns[$found[1]] ?? '', $needle)) {
                $rows[] = (object) ['id' => (string) $id, 'value' => $columns[$found[1]]];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $id = reset($where);
        $this->updates[] = ['table' => $table, 'id' => $id, 'data' => $data];

        foreach ($data as $column => $value) {
            $this->tables[$table][(int) $id][$column] = $value;
        }

        return 1;
    }
}
