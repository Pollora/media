# Changelog

All notable changes to `pollora/media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-10-19

## [0.2.0] - 2026-10-19

### Added

- Private media: a media flagged private (`_pollora_media_private`, or the `pollora/media/is_private` filter) is served through a signed URL that expires (`private expiry`, `+6 hours` by default) on its URL, the URL of its sizes and every source of its `srcset`; a public media keeps its plain URL, and nothing is asked of the disk to tell them apart. `PrivateMedia::setPrivate()` changes the visibility of every file of a media on the disk, and a media created private is private from the start
- The `MediaStorage` port gives `visibility()`, `setVisibility()` and `temporaryUrl()` (null when the disk cannot sign); `FlysystemMediaStorage` takes the signing function of the host, as a Laravel disk has its own
- While WordPress builds the URL of an image size from the URL of its media, that one stays plain, and the size is signed when `wp_get_attachment_image_src` has it
- `AttachmentFiles`: every file of a media, with those a plugin declares (`pollora/media/attachment_files`)
- Hooks to extend and audit: the `pollora/media/signed_url` filter and the `pollora/media/stored`, `pollora/media/deleted` and `pollora/media/visibility_changed` actions
- `wp pollora media files <id>` and `wp pollora media visibility <id> --private|--public`

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
