<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

use InvalidArgumentException;
use Pollora\Media\Domain\Contracts\MediaStorage;
use Pollora\Media\Domain\Models\ImageSizeSpec;

/**
 * Makes the files of the declared sizes for the images already in the library,
 * which were uploaded before the size was declared. Adding or changing a size costs
 * nothing until then; this is what to run before a heavy page goes live.
 */
final readonly class SizeWarmer
{
    private const int BATCH = 100;

    public function __construct(
        private DeclaredSizes $declared,
        private MediaStorage $storage,
    ) {}

    /**
     * @param  string|null  $only  The name of one declared size
     * @param  string|null  $since  A date: only the images uploaded after it
     * @return array{checked: int, made: int, failed: int}
     *
     * @throws InvalidArgumentException When the size is not declared
     */
    public function warm(?string $only = null, ?string $since = null): array
    {
        $specs = $this->declared->specs();

        if ($only !== null) {
            if (! isset($specs[$only])) {
                throw new InvalidArgumentException(sprintf('The size "%s" is not declared.', $only));
            }

            $specs = [$only => $specs[$only], ...array_filter($specs, static fn ($spec): bool => str_starts_with($spec->name, $only.'-') && preg_match('/-\d+w$/', $spec->name) === 1)];
        }

        $report = ['checked' => 0, 'made' => 0, 'failed' => 0];
        $page = 1;

        do {
            $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'posts_per_page' => self::BATCH, 'paged' => $page];

            if ($since !== null) {
                $args['date_query'] = [['after' => $since]];
            }

            $ids = \get_posts($args);

            foreach ($ids as $id) {
                $this->warmOne((int) $id, $specs, $report);
            }

            $page++;
        } while (count($ids) === self::BATCH);

        return $report;
    }

    /**
     * @param  array<string, ImageSizeSpec>  $specs
     * @param  array{checked: int, made: int, failed: int}  $report
     */
    private function warmOne(int $id, array $specs, array &$report): void
    {
        $metadata = \wp_get_attachment_metadata($id);

        if (! is_array($metadata) || ! $this->declared->isMakeable($metadata)) {
            return;
        }

        $report['checked']++;
        $changed = false;

        foreach ($specs as $spec) {
            if ($this->storage->exists($this->declared->fileFor($metadata, $spec))) {
                continue;
            }

            $entry = $this->declared->make($metadata, $spec);

            if ($entry === null) {
                $report['failed']++;

                continue;
            }

            $metadata['sizes'][$spec->name] = $entry;
            $report['made']++;
            $changed = true;
        }

        if ($changed) {
            \wp_update_attachment_metadata($id, $metadata);
        }
    }
}
