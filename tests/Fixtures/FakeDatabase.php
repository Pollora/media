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
        // As wpdb does, `%i` takes its identifier at once; the other placeholders keep their values
        $kept = [];
        $query = preg_replace_callback('/%[ids]/', function (array $placeholder) use (&$values, &$kept): string {
            $value = array_shift($values);

            if ($placeholder[0] === '%i') {
                return (string) $value;
            }

            $kept[] = $value;

            return $placeholder[0];
        }, $query);
        $values = $kept;

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
        // LIKE as MySQL reads it: `%` any run of characters, `_` any one, a backslash before one of them for itself
        preg_match_all('/\\\\(.)|(%)|(_)|(.)/s', (string) $like, $tokens, PREG_SET_ORDER);
        $pattern = implode('', array_map(static fn (array $token): string => match (true) {
            ($token[2] ?? '') !== '' => '.*',
            ($token[3] ?? '') !== '' => '.',
            default => preg_quote(($token[4] ?? '') !== '' ? $token[4] : $token[1], '/'),
        }, $tokens));
        $rows = [];

        foreach ($this->tables[$found[2]] ?? [] as $id => $columns) {
            if ($id > $last && preg_match('/^'.$pattern.'$/Ds', $columns[$found[1]] ?? '') === 1) {
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
