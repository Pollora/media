# Changelog

All notable changes to `pollora/media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.10.0] - 2026-11-24

### Fixed

- The three thumbnail drivers now answer the same way on the cases the adapter contract tests had listed as known deviations (#58), and `THUMBNAIL_KNOWN_DEVIATIONS` is empty. `PolloraThumbnailDriver` gives the same file name for `/a.jpg` and `a.jpg`. `BunnyThumbnailDriver` encodes `?` and `#` in the key of the original, so `sizeOf()` finds the class of `what?.jpg` again. `PolloraThumbnailDriver::sizeOf()` and `ImgproxyThumbnailDriver::sizeOf()` answer only the name of a declared size, and the imgproxy driver no longer recognises the URL of a size it refuses (`default`). The names and URLs of the keys that were valid (no leading slash, no `?` or `#`) are unchanged.
- `SizeGeometry` no longer divides by zero for an original WordPress recorded with no dimensions: a scale or a contain keeps the dimensions it was given (no geometric resize) and `coverRegion()` keeps the whole original, so `DeclaredSizes::downsize()` and the drivers' `url()` never throw `DivisionByZeroError`.

### Changed

- **BREAKING**: `PolloraThumbnailDriver` takes the declared sizes (`ImageSizes`) as a third constructor argument and `ImgproxyThumbnailDriver` as its third, before the base URL (`new ImgproxyThumbnailDriver($signer, $config, $sizes, $baseUrl, $bucket)`), to recognise only declared sizes as `BunnyThumbnailDriver` already does. `ThumbnailDrivers::make()` and `DeclaredSizes` pass them; only code that builds these drivers by hand changes.

||||||| d54194b
- The unit suite loads its stand-in for WP-CLI from `tests/bootstrap.php` instead of `tests/Pest.php`, which the integration suite also loads (a real WordPress must not find a `WP_CLI` that is not WP-CLI). No change to the package.

### Security

- A size signature is never made with an empty key (#53). With an empty `app.key` (or a blank salt) the HMAC of a file name was computable by anyone, who could then have the declared sizes made for any original. `SizedFileName` now throws `InvalidMediaConfigurationException::emptySigningKey()` when the key it resolves is empty or blank, with a message that says what to set (`APP_KEY`, or the WordPress salts); the key is still resolved when a signature is made, so a key known late works, and an installation with a key sees no change: the file names and signatures are what they were (the signature stays cut to 12 hexadecimal digits, which a name already issued depends on).

### Added

- An integration suite (`tests/Integration`, `composer test:integration`) that runs against a real WordPress: the upload directory moved to the wrapper and the public URL, an uploaded image stored with the sizes WordPress and the module make through the real image editors, the URL of a declared size and the file made when it is first asked for, the focal point meta written over REST (an editor only, a point out of range refused) with the covers made around it, the legacy URLs replaced in the content, and the private flag read from the table. It is not part of the default `pest` run; the harness's optional `WordPress integration` job starts it.
- A focal point per image (#18). The post meta `_pollora_focal_point` (`{x, y}`, fractions of the original from 0 to 1, kept to a thousandth) is declared with `register_post_meta()` and a schema, so it is read and written over REST by a user who can edit the attachment. `FocalPoint` validates it (a value that is not a point is no point), `FocalPoints` reads it typed, once per media (`$media->focalPoints()->for($id)`, `set()`, `clear()`), and the new `pollora/media/focal_point` filter has the last word. With a point, `SizeGeometry::coverRegion(..., $anchor, $focal)` centres the region on it, clamped to the edges, instead of using the anchor; dimensions, `srcset` and the sizes that do not crop are unchanged. `ImageSizeSpec` gets an optional `$focal` and `focusedOn()`; the point is part of `fingerprint()` and of the signature, and the file of a cover around one is named `photo-card-600x400-f0250_0750-{signature}.webp`, so moving the point never leaves a stale copy and the fallback route makes the file from its name alone, with no lookup. The files of a size of an image without a point keep their names. Changing or removing the point deletes the files made around the previous one and makes the `eager()` covers around the new one; `thumbs prune` removes the crops around a point that is not the image's any more; the files go with the media. imgproxy and Bunny cannot hold a point in a preset or a class: a cover of an image with a point is made by the module whatever the driver (so the fallback route is now registered with these drivers too), a private media stays kept from the service. `ThumbnailRoute` accepts the new segment. `DeclaredSizes::make()`, `fileFor()` and `existingFiles()` take an optional focal point, `SizePruner` an optional `$focalOf` closure, `SizedFileName::parse()` returns the point as `focal`. No editing screen yet.

## [0.9.0] - 2026-11-19

### Security

- A request for a size no longer gets past the name checks with a trailing newline or another control character (the `$` of the patterns matched before a final newline): it is a 404 before anything reaches the storage, where it used to end in a 500. The route and the name parser now anchor with `D`. The guarantee that only declared, signed sizes are ever made is now locked by `DeclaredSizesContractTest` (SEC-06)

- The remaining name checks anchor with `D` too: a size declared as `card` followed by a newline is refused (it was accepted), the size segment of an imgproxy URL, the sizes already made that stand in for one that cannot be made, and the parent lookup of an extra width ignore a name ending with a newline. The pruner compares a file signature with `hash_equals()` instead of `!==` (SEC-06)

- The id of a Bunny pull zone (it goes into the URL of the Bunny API) no longer accepts a trailing newline: `"1234\n"` was accepted by `/^\d+$/` and is now refused before any request. The loopback host check of the thumbnail export and the multisite folder match of the uploads base anchor with `D` too, as defence in depth (a host parsed by `parse_url()` and a WordPress `basedir` cannot carry a newline today) (SEC-06)

### Changed

- A media disk that cannot be built (`InvalidMediaConfigurationException`), a thumbnail that cannot be made (`ImageProcessingFailedException`) and a Bunny request that fails (`BunnyRequestFailedException`) throw named exceptions instead of a bare `\RuntimeException`. They extend `\RuntimeException`, so a `catch (\RuntimeException)` keeps working, and the messages keep their wording, with a hint added where one was missing

## [0.8.0] - 2026-10-20

### Added

- An anchor for `cover()`: `ImageSize::add('portrait')->cover(400, 500, Anchor::Top)`. `Anchor` has the nine positions of `add_image_size()` (`Center`, `Top`, `Bottom`, `Left`, `Right`, `TopLeft`, `TopRight`, `BottomLeft`, `BottomRight`). The region a cover keeps is placed by the anchor instead of centred (`SizeGeometry::coverRegion(..., $anchor)`), the dimensions do not change, and a non-centred anchor is part of `ImageSizeSpec::fingerprint()`, so it changes the name of the files and the `v` of a service URL. The fingerprint of a centred size is what it was, so no existing file or URL changes. `ImageSizeSpec` refuses an anchor on a fit that is not a cover. imgproxy: the preset gets a `gravity` (`no`, `so`, `we`, `ea`, `nowe`, `noea`, `sowe`, `soea`), checked on imgproxy 4 (a left, right, top and bottom anchor keep the part of the image they name). Bunny: a cover anchored away from the centre is refused (`BunnyClasses::refusal()`), reported by `thumbs export` and `pollora:doctor`, since no parameter of a class is known to move its crop

## [0.7.1] - 2026-10-20

### Fixed

- The `eager()` sizes of an image edited in the media library (cropped, rotated, flipped, or put back by *Restore image*) are made, as at upload. WordPress writes the new original and its own sizes, then saves the metadata without `wp_generate_attachment_metadata`, so the sizes were only worked out in the URLs and never made: a disk served directly, with no fallback, returned broken images until `thumbs warm` ran. `DeclaredSizes::remake()` runs on `wp_update_attachment_metadata` and acts only when the `file` of the metadata changes. The sizes of the previous original stay on the disk, where *Restore image* expects them, and `thumbs prune` keeps them
- README: the *Apply changes to: thumbnail only* option of the image editor is not followed by a declared size, which is always made from the original

## [0.7.0] - 2026-10-20

### Added

- `--exclude=<patterns>` on `wp pollora media migrate` (comma-separated), and `MediaMigrator::migrate(exclude: [...])`: paths matching a pattern (`woocommerce_uploads/*`, `*.zip`; `*` matches across folders) are not copied. `MigrationReport::$excluded` lists them, and the progress callback receives `exclude`. Meant for folders of private files (protected downloads, logs, form attachments, backups) that a public disk would serve to anyone

### Fixed

- `migrate` sent no `Cache-Control` with the files it copied, whereas an upload does: a migrated file was served without the long cache header. `MediaMigrator` takes the header as its third constructor argument, and `wp pollora media migrate` passes `cache_control`
- `migrate` ignored the visibility of the source: a private file copied to a disk with a public default became public. The visibility of each file is now sent with the write, in the same request (no second call per file)

## [0.6.1] - 2026-10-19

### Fixed

- The URL of a size made by a service carries a version of the size (`?class=card&v=3f9a1c2b` for Bunny, `…@webp?v=3f9a1c2b` for imgproxy). Found by running the whole thing on a real site and a real Bunny pull zone: after a class was changed, the CDN kept serving the old variant under the same `?class=card` URL, whereas a changed size changes the name of the file of the default driver. The version is not signed and the services ignore it, so nothing is asked of them; `ImageSizeSpec::version()` derives it from the fit, dimensions, format, quality and upscaling
- README: Bunny rounds the height of a resize and then takes the width back to the integer below, so a size can be served one pixel narrower than advertised (1199 for 1200) when the ratio of the original does not divide exactly; imgproxy serves exactly the advertised dimensions. The 0.6.0 notes said the dimensions served are the advertised ones, which was true to the pixel only for imgproxy

## [0.6.0] - 2026-10-19

### Added

- Sizes made by a service: the `bunny` driver (`photo.jpg?class=card`, an image class of Bunny Optimizer) and the `imgproxy` driver (a URL signed with HMAC-SHA256 that names a preset, for imgproxy 4.0.x in presets-only mode; the source is the URL of the library or an `s3://` object), chosen with `POLLORA_MEDIA_THUMBNAILS_DRIVER`. No file is made, no fallback route is registered, and `thumbs warm` has nothing to do. The signature follows the documented vector, with `IMGPROXY_SIGNATURE_SIZE`
- `wp pollora media thumbs export --driver=bunny|imgproxy`: one Bunny class or imgproxy preset per declared size and per extra width. Bunny: the classes are read from the pull zone, merged by name and written back with `--execute` (a dry run otherwise); nothing is deleted and the forced classes setting is never touched. imgproxy: prints `IMGPROXY_PRESETS` and the settings that go with it, without the key or the salt
- `POLLORA_MEDIA_BUNNY_PULL_ZONE`, `_BUNNY_API_KEY`, `POLLORA_MEDIA_IMGPROXY_URL`, `_KEY`, `_SALT`, `_SIGNATURE_SIZE` and `_SOURCE`

### Fixed

- Checked on a real Bunny pull zone and a real imgproxy 4.0.17 (S3 source included): the dimensions advertised for every size are the ones served. Bunny accepts only letters and digits in a class name, so `hero-640w` is the class `hero640w` and two sizes that collide are refused; Bunny replaces the whole list of classes it is sent, so the export merges before it writes

### Changed

- The `ThumbnailDriver` port gains `sizeOf()` (the declared size a URL stands for) and `refusal()` (why a size cannot be served). The `srcset` of the extra widths is now built from the driver, not from the file name of the module, so it works with any driver
- A private media never goes to a service that makes the sizes: its sizes and its `srcset` stay those of WordPress, signed as before

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
