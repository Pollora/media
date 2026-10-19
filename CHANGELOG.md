# Changelog

All notable changes to `pollora/media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-10-19

## [0.4.0] - 2026-10-19

### Added

- Sizes made on demand: a size that is not `eager()` is made the first time its URL is asked for, at the exact path of the URL, and answered with `Cache-Control: public, max-age=31536000, immutable`; the web server or the CDN serves it without PHP afterwards. `ThumbnailService` checks the name, the signature, the extension and the path without asking the disk, then the dimensions of the original, its weight and its visibility; `ThumbnailRoute` answers on `parse_request` for WordPress without Laravel. The `ThumbnailDriver` port says where a size lives (`PolloraThumbnailDriver`)
- Limits per server, with `flock()` locks that need neither WordPress nor Laravel: one process per file, a ceiling of simultaneous sizes (`POLLORA_MEDIA_THUMBNAILS_CONCURRENCY`, 4), no size for an original above `_MAX_PIXELS` (50 million) or `_MAX_BYTES` (30 MiB), and a request that waits more than `_WAIT` seconds (10) gets the nearest size that exists, with no cache
- The files of the sizes made on demand are deleted with their media and follow its visibility, though WordPress does not know them

### Changed

- Only the sizes declared `eager()` are made when an image is uploaded; the others wait for their first request, or `thumbs warm`
- The file name of a size with extra widths (`photo-hero-400w-400x267-{signature}.webp`) is read correctly, which `thumbs prune` relies on

### Added

- Declared image sizes: `ImageSize::add('card')->cover(600, 400)->webp(75)`, with `cover()`, `scale()`, `contain()`, `webp()`, `avif()`, `original()`, `widths()`, `eager()` and `upscale()`; WebP at quality 80 by default. `ImageSizes` is the registry, `ImageSizeSpec` a checked size
- The file of a size is named `photo-card-600x400-{signature}.webp`, the signature being an HMAC of the original and of the parameters of the size: changing a size changes its name. The URL, width, height and `srcset` of a size come from the metadata of the original alone (`DeclaredSizes`), whether the file exists or not; the sizes are made when an image is uploaded, with the image editor of WordPress (`WordPressImageProcessor`, behind the `ImageProcessor` port). The sizes WordPress registers itself are left as they are
- `wp pollora media thumbs warm` makes the missing files of the declared sizes for the images already in the library, and `wp pollora media thumbs prune` deletes the files of a size that was changed or removed (a dry run unless `--execute`)

## [0.3.0] - 2026-10-19

### Added

- Private media: a media flagged private (`_pollora_media_private`, or the `pollora/media/is_private` filter) is served through a signed URL that expires (`private expiry`, `+6 hours` by default) on its URL, the URL of its sizes and every source of its `srcset`; a public media keeps its plain URL, and nothing is asked of the disk to tell them apart. `PrivateMedia::setPrivate()` changes the visibility of every file of a media on the disk, and a media created private is private from the start
- The `MediaStorage` port gives `visibility()`, `setVisibility()` and `temporaryUrl()` (null when the disk cannot sign); `FlysystemMediaStorage` takes the signing function of the host, as a Laravel disk has its own
- While WordPress builds the URL of an image size from the URL of its media, that one stays plain, and the size is signed when `wp_get_attachment_image_src` has it
- `AttachmentFiles`: every file of a media, with those a plugin declares (`pollora/media/attachment_files`)
- Hooks to extend and audit: the `pollora/media/signed_url` filter and the `pollora/media/stored`, `pollora/media/deleted` and `pollora/media/visibility_changed` actions
- `wp pollora media files <id>` and `wp pollora media visibility <id> --private|--public`

## [0.2.0] - 2026-10-19

### Added

- A local copy of every file written or read during a request, up to 16 MiB (`StreamWrapper::limitLocalCopies()`), removed at the end of the request, and the size and date of a file written are known without asking the disk. An upload of an image with nine sizes drops from about 70 round trips to the disk besides the writes (37 existence checks, 20 stats, 12 reads) to a handful of existence checks; both WordPress image editors (GD and Imagick) work on the wrapper as they are
- Asynchronous thumbnails with `async_thumbnails` / `POLLORA_MEDIA_ASYNC_THUMBNAILS`: `thumbnail` and `medium` during the upload, the other sizes in an asynchronous action (`pollora/media/generate_subsizes`) on Action Scheduler, WP-Cron or the queue the host provides. `WordPressMedia` and `AsyncThumbnails` take the host's `Pollora\Hook\Domain\Contract\Action`; `Media::register()` gives them the package's own. The package requires `pollora/hook` `^2.0.1`
- The personal data export works on any disk: the archive is built in a local folder, copied to the disk, linked through the public URL, and expired archives are deleted from the disk (`PrivacyExports`)

### Changed

- The upload directory is `pollora://uploads`, not `pollora://`: WordPress trims the trailing slashes of the base it is given, which turned `pollora://` into `pollora:` and a path on the local disk. `pollora://` paths without the `uploads` segment still reach the same files
- `unlink()` on the wrapper deletes without asking the disk first

## [0.1.0] - 2026-10-19

### Added

- The media library on any Flysystem disk behind two settings, the disk and the public URL: `MediaConfig`, `MediaUrl`, the `MediaStorage` port with its Flysystem adapter, and the `pollora://` stream wrapper (virtual directories, buffered writes, `Cache-Control` on write, per-request stat cache)
- The WordPress hooks that make the core follow: `upload_dir`, `pre_wp_unique_filename_file_list`, `delete_attachment` (with the `pollora/media/attachment_files` filter), `wp_resource_hints`, and `the_content` / `render_block` for `legacy_urls`
- Without Laravel: `Media::register()`, `ConstantsConfiguration` and the `POLLORA_MEDIA_*` constants, and the `pollora/media/filesystem` filter for any other service. The S3 driver (`league/flysystem-aws-s3-v3`) is a suggestion, not a dependency
- `MediaMigrator` and `wp pollora media migrate` (dry run unless `--execute`, repeatable), `UrlReplacement` for the database (serialized values and block JSON), `MediaStatus` and `wp pollora media status`
