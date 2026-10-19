<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/media.png" width="100%" alt="Pollora Media: WordPress media on any disk, behind two settings">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/media"><img src="https://img.shields.io/packagist/v/pollora/media" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/media"><img src="https://img.shields.io/packagist/dt/pollora/media" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/media/actions/workflows/tests.yml"><img src="https://github.com/Pollora/media/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/media" alt="License"></a>
</p>

WordPress media on any Flysystem disk (local, S3, R2, Scaleway…), behind two settings: the disk and the public URL. WordPress goes on using its own file functions through a `pollora://` stream wrapper and the `upload_dir` filter, so Gutenberg, ACF, Meta Box, WooCommerce and imports follow without adaptation, and a media URL is the public URL and a relative path, never asked to the disk.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you from `config/media.php`. It runs without Laravel too: in a plugin, a Bedrock site or any PHP project.

## Principles

- **Two settings, no more**: the disk and the public URL. The rest is derived.
- **The WordPress core is the integration point**: the `upload_dir` filter and a `pollora://` stream wrapper. Gutenberg, ACF, Meta Box, WooCommerce and imports follow without adaptation.
- **A media URL is built, never asked**: the public URL and the relative path of the file (`https://media.client.fr/2026/10/photo.jpg`). No call to the disk while a public page renders.
- **Nothing specific to a plugin**: what goes beyond the core is a WordPress hook (`pollora/media/*`).

## Installation

```bash
composer require pollora/media
```

Requires PHP 8.3+, WordPress and [Flysystem](https://flysystem.thephpleague.com) 3. For S3 and compatible services (R2, Scaleway, MinIO, OVH) add the driver:

```bash
composer require league/flysystem-aws-s3-v3
```

## Without Laravel

Set the library in `wp-config.php`, then register the module from a must-use plugin or the first plugin loaded, before WordPress builds its upload directory.

```php
// wp-config.php
define('POLLORA_MEDIA_DRIVER', 's3');
define('POLLORA_MEDIA_BUCKET', 'client-media');
define('POLLORA_MEDIA_REGION', 'eu-west-3');
define('POLLORA_MEDIA_URL', 'https://media.client.fr');
```

```php
// mu-plugins/media.php
\Pollora\Media\Media::register();
```

Without any constant, the media stay where WordPress puts them.

| Constant | Meaning |
|---|---|
| `POLLORA_MEDIA_URL` | Public URL of the library: a media URL is this and a relative path. Default: `WP_CONTENT_URL/uploads` |
| `POLLORA_MEDIA_LEGACY_URLS` | Old base URLs of the library, replaced by `POLLORA_MEDIA_URL` in the content and in rendered blocks during a transition (an array, or a comma separated list) |
| `POLLORA_MEDIA_CACHE_CONTROL` | `Cache-Control` set on write. Default: `public, max-age=31536000, immutable` |
| `POLLORA_MEDIA_PRIVATE_EXPIRY` | Life of a signed URL. Default: `+6 hours` |
| `POLLORA_MEDIA_ASYNC_THUMBNAILS` | Generate the sizes after the upload |
| `POLLORA_MEDIA_DRIVER` | `local` (default) or `s3` |
| `POLLORA_MEDIA_ROOT` | Local folder of the library. Default: `WP_CONTENT_DIR/uploads` |
| `POLLORA_MEDIA_BUCKET`, `_REGION`, `_KEY`, `_SECRET`, `_ENDPOINT`, `_PATH_STYLE` | S3 and compatible services. Without `_KEY`, the SDK's own credential chain applies |

For another service (Azure, Google Cloud, SFTP…), the `pollora/media/filesystem` filter provides a `League\Flysystem\FilesystemOperator` directly:

```php
add_filter('pollora/media/filesystem', fn () => new Filesystem(new SftpAdapter(/* … */)));
```

`Media::register()` also takes a filesystem and a `MediaConfig`, for a project that builds them itself.

## What it does

| Hook | Role |
|---|---|
| `upload_dir` | `path` and `basedir` point to the wrapper, `url` and `baseurl` to the public URL. The date folder, and the folder WordPress adds for a site of a network, are kept |
| `pre_wp_unique_filename_file_list` | WordPress does not list a remote folder to find a free name; the exact name is still checked |
| `delete_attachment` | Files a plugin keeps next to a media are deleted with it (`pollora/media/attachment_files` filter, a list of paths) |
| `wp_resource_hints` | `dns-prefetch` to the media domain when it differs from the site's |
| `the_content`, `render_block` | Legacy URLs are replaced by the public URL, with a plain string replacement. Hooked only when `legacy_urls` is set |

| `intermediate_image_sizes_advanced`, `wp_generate_attachment_metadata` | With `POLLORA_MEDIA_ASYNC_THUMBNAILS` (or `async_thumbnails`): only `thumbnail` and `medium` are made during the upload; the other sizes are generated afterwards by an asynchronous action (Action Scheduler, WP-Cron, or the queue a framework provides). WordPress serves the nearest size it has in the meantime |
| `wp_privacy_exports_dir`, `wp_privacy_exports_url`, `wp_privacy_personal_data_export_file_created`, `wp_privacy_delete_old_export_files` | The personal data export builds its archive locally (`ZipArchive` cannot write to a wrapper), copies it to the disk, links it through the public URL, and the old archives are deleted from the disk |

The `pollora://` wrapper sends a file to the disk when its stream is closed, in one write. Directories do not exist on an object store, so they are virtual and answer without asking the disk (a path with an extension is a file); what is asked about a file is remembered for the rest of the request. A file written or read during a request, up to 16 MiB, is also kept in a local copy until the request ends: WordPress reads an uploaded image again for its size, its EXIF data, its sizes and its metadata, and none of it goes back to the disk. One upload of an image with nine sizes costs ten writes and a handful of existence checks; a read of the original, a stat or a listing never happens. Both the GD and the Imagick editors of WordPress work on the wrapper as they are.

The wrapper's root is `pollora://uploads`: WordPress trims the trailing slashes of the base it is given, which would turn `pollora://` into a path on the local disk. A path with or without that first segment reaches the same file.

### Limits

- WordPress lists the folder of a new file to avoid names that clash with image sizes already there. On the wrapper it does not, to avoid a remote listing per upload: the exact name is still checked, but an orphan resized file with the same stem is not detected.
- A plugin that writes under `WP_CONTENT_DIR . '/uploads'` by hand ignores `upload_dir`, so its files stay local. A plugin that uses `ZipArchive` or a C library on a path fails on a stream wrapper.
- Files written by WooCommerce in `uploads/wc-logs` go through the wrapper too; keep the logs on a local path.

## Commands

```bash
wp pollora media status                       # the public URL, and a test write
wp pollora media migrate                      # dry run: what would be copied from the uploads folder
wp pollora media migrate --execute            # copy the files; the source is never changed
wp pollora media migrate --execute --replace-urls --old-url=https://site.test/content/uploads
```

`migrate` skips a file already on the target with the same size, so it can be repeated. `--replace-urls` replaces the old base URL in post content and excerpts and in post, term and user meta and options, serialized values and the JSON of blocks included; the `guid` is left alone. Without `--execute`, nothing is written.

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
