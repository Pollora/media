<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use InvalidArgumentException;
use Pollora\Media\Domain\Models\FocalPoint;

/**
 * The focal point of an image: the part an editor wants kept whatever the crop. It is a post meta of the
 * attachment, `_pollora_focal_point`, `{x, y}` as fractions of the original from 0 to 1, declared with
 * `register_post_meta()` so the REST API (the block editor, a headless front-end) reads and writes it,
 * for a user who can edit the attachment.
 *
 * It is read with the attachment, once per media in a request, and a malformed value is no point at all.
 * WordPress deletes the meta with the attachment.
 */
final class FocalPoints
{
    /** The post meta that holds the focal point. */
    public const string META = '_pollora_focal_point';

    /** @var array<int, FocalPoint|null> */
    private array $known = [];

    /** @var array<string, FocalPoint>|null */
    private ?array $byFile = null;

    public function register(): void
    {
        \register_post_meta('attachment', self::META, [
            'type' => 'object',
            'single' => true,
            'description' => 'The focal point of the image: x and y, fractions of the original from 0 to 1.',
            'sanitize_callback' => $this->sanitize(...),
            'auth_callback' => static fn (bool $allowed, string $key, int $attachmentId): bool => \current_user_can('edit_post', $attachmentId),
            'show_in_rest' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                    'required' => ['x', 'y'],
                    'additionalProperties' => false,
                ],
            ],
        ]);
    }

    /**
     * The focal point of an image, or null when it has none. The `pollora/media/focal_point` filter has the last
     * word: it receives `['x' => 0.25, 'y' => 0.75]` or null and returns the same shape (null for no point); a
     * value that is not a point is ignored and the stored one stands.
     */
    public function for(int $attachmentId): ?FocalPoint
    {
        if (array_key_exists($attachmentId, $this->known)) {
            return $this->known[$attachmentId];
        }

        $stored = FocalPoint::tryFrom(\get_post_meta($attachmentId, self::META, true));
        $filtered = \apply_filters('pollora/media/focal_point', $stored?->toArray(), $attachmentId);
        $point = $filtered === null ? null : (FocalPoint::tryFrom($filtered) ?? $stored);

        return $this->known[$attachmentId] = $point;
    }

    /**
     * @throws InvalidArgumentException When a coordinate is not a number from 0 to 1
     */
    public function set(int $attachmentId, float $x, float $y): void
    {
        \update_post_meta($attachmentId, self::META, (new FocalPoint($x, $y))->toArray());
        $this->forget($attachmentId);
    }

    public function clear(int $attachmentId): void
    {
        \delete_post_meta($attachmentId, self::META);
        $this->forget($attachmentId);
    }

    public function forget(int $attachmentId): void
    {
        unset($this->known[$attachmentId]);
        $this->byFile = null;
    }

    /**
     * What is stored in the meta: a valid point, as it is written, or nothing.
     */
    public function sanitize(mixed $value): mixed
    {
        return FocalPoint::tryFrom($value)?->toArray() ?? '';
    }

    /**
     * The focal point of each original that has one, by path: what the pruner compares the files to.
     *
     * @return array<string, FocalPoint>
     */
    public function byFile(): array
    {
        if ($this->byFile !== null) {
            return $this->byFile;
        }

        $points = [];

        foreach (\get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'nopaging' => true, 'meta_key' => self::META]) as $id) {
            $file = (string) \get_post_meta((int) $id, '_wp_attached_file', true);
            $point = $this->for((int) $id);

            if ($file !== '' && $point instanceof FocalPoint) {
                $points[$file] = $point;
            }
        }

        return $this->byFile = $points;
    }
}
