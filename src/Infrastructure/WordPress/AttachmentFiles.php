<?php

declare(strict_types=1);

namespace Pollora\Media\Infrastructure\WordPress;

/**
 * Every file of a media on the disk: the original, each size, the original
 * image of a scaled one, and the files a plugin keeps next to it
 * (`pollora/media/attachment_files`). Paths are relative to the library.
 */
final class AttachmentFiles
{
    /**
     * @param  (\Closure(array<string, mixed>): list<string>)|null  $declared  The files of the declared sizes that exist for an original
     */
    public function __construct(private readonly ?\Closure $declared = null) {}

    /**
     * @return list<string>
     */
    public function for(int $attachmentId): array
    {
        $metadata = \wp_get_attachment_metadata($attachmentId);
        $metadata = is_array($metadata) ? $metadata : [];
        $file = (string) ($metadata['file'] ?? '');

        if ($file === '') {
            return $this->declaredBy($attachmentId, []);
        }

        $directory = dirname($file) === '.' ? '' : dirname($file).'/';
        $files = [$file];

        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            $name = $this->fileOf($size);

            if ($name !== null) {
                $files[] = $directory.$name;
            }
        }

        if (isset($metadata['original_image'])) {
            $files[] = $directory.$metadata['original_image'];
        }

        if ($this->declared instanceof \Closure) {
            $files = [...$files, ...($this->declared)($metadata)];
        }

        return $this->declaredBy($attachmentId, array_values(array_unique($files)));
    }

    private function fileOf(mixed $size): ?string
    {
        return is_array($size) && is_string($size['file'] ?? null) ? $size['file'] : null;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function declaredBy(int $attachmentId, array $files): array
    {
        $extra = \apply_filters('pollora/media/attachment_files', [], $attachmentId);

        return array_values(array_unique([...$files, ...array_map(static fn (mixed $path): string => ltrim((string) $path, '/'), is_array($extra) ? $extra : [])]));
    }
}
