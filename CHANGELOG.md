# Changelog

All notable changes to `pollora/media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The media library on any Flysystem disk behind two settings, the disk and the public URL: `MediaConfig`, `MediaUrl`, the `MediaStorage` port with its Flysystem adapter, and the `pollora://` stream wrapper (virtual directories, buffered writes, `Cache-Control` on write, per-request stat cache)
- The WordPress hooks that make the core follow: `upload_dir`, `pre_wp_unique_filename_file_list`, `delete_attachment` (with the `pollora/media/attachment_files` filter), `wp_resource_hints`, and `the_content` / `render_block` for `legacy_urls`
- Without Laravel: `Media::register()`, `ConstantsConfiguration` and the `POLLORA_MEDIA_*` constants, and the `pollora/media/filesystem` filter for any other service. The S3 driver (`league/flysystem-aws-s3-v3`) is a suggestion, not a dependency
- `MediaMigrator` and `wp pollora media migrate` (dry run unless `--execute`, repeatable), `UrlReplacement` for the database (serialized values and block JSON), `MediaStatus` and `wp pollora media status`
