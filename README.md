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
| `POLLORA_MEDIA_THUMBNAILS_DRIVER` | Who makes the sizes: `pollora` (default), `bunny` or `imgproxy` (see [Sizes made by a service](#sizes-made-by-a-service)) |
| `POLLORA_MEDIA_BUNNY_PULL_ZONE`, `_API_KEY` | Bunny pull zone id and access key, only to export the classes |
| `POLLORA_MEDIA_IMGPROXY_URL`, `_KEY`, `_SALT`, `_SIGNATURE_SIZE`, `_SOURCE` | imgproxy: its URL, hexadecimal key and salt, signature size (32), source `url` or `s3` |
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

| `wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_calculate_image_srcset` | Private media only: the URL, the URL of a size and every source of a `srcset` are signed and expire. A public media keeps its plain URL, and nothing is asked of the disk to tell them apart |
| `wp_generate_attachment_metadata` | A media created private (see below) is private on the disk from the start |

The `pollora://` wrapper sends a file to the disk when its stream is closed, in one write. Directories do not exist on an object store, so they are virtual and answer without asking the disk (a path with an extension is a file); what is asked about a file is remembered for the rest of the request. A file written or read during a request, up to 16 MiB, is also kept in a local copy until the request ends: WordPress reads an uploaded image again for its size, its EXIF data, its sizes and its metadata, and none of it goes back to the disk. One upload of an image with nine sizes costs ten writes and a handful of existence checks; a read of the original, a stat or a listing never happens. Both the GD and the Imagick editors of WordPress work on the wrapper as they are.

The wrapper's root is `pollora://uploads`: WordPress trims the trailing slashes of the base it is given, which would turn `pollora://` into a path on the local disk. A path with or without that first segment reaches the same file.

### Limits

- WordPress lists the folder of a new file to avoid names that clash with image sizes already there. On the wrapper it does not, to avoid a remote listing per upload: the exact name is still checked, but an orphan resized file with the same stem is not detected.
- A plugin that writes under `WP_CONTENT_DIR . '/uploads'` by hand ignores `upload_dir`, so its files stay local. A plugin that uses `ZipArchive` or a C library on a path fails on a stream wrapper.
- Files written by WooCommerce in `uploads/wc-logs` go through the wrapper too; keep the logs on a local path.

## Image sizes

Declare the sizes your design uses, instead of leaving WordPress to make every size for every image:

```php
use Pollora\Media\ImageSize;   // in Pollora: Pollora\Support\Facades\ImageSize

ImageSize::add('card')->cover(600, 400)->webp(75);
ImageSize::add('hero')->scale(width: 1920)->webp(70)->widths([640, 1024, 1440]);
ImageSize::add('thumb')->cover(150, 150)->eager();
ImageSize::add('portrait')->cover(400, 500, Anchor::Top);   // use Pollora\Media\Domain\Enums\Anchor
```

| Option | Role | Default |
|---|---|---|
| `cover(w, h, anchor)`, `scale(width:, height:)`, `contain(w, h)` | How the image fits: crop to the box, resize keeping the ratio, fit inside the box | none: one is required |
| `webp(q)`, `avif(q)`, `original()` | The format of the file and its quality | WebP, quality 80 |
| `widths([...])` | Extra widths for a `srcset`, each a bounded size of its own | none |
| `eager()` | Made when the image is uploaded | no: made when first asked for |
| `upscale()` | Allow enlarging an original smaller than the size | off: `scale()` and `contain()` never enlarge, and `cover()` keeps the ratio of the box at the size the original allows |

**Where a cover crops.** A `cover()` keeps the center of the image. The `Anchor` argument keeps another part, the nine positions of `add_image_size()`: `Center` (default), `Top`, `Bottom`, `Left`, `Right`, `TopLeft`, `TopRight`, `BottomLeft`, `BottomRight`. It is only meaningful for a cover (any other fit refuses it), it never changes the dimensions of the size, and it is part of the signature: changing it changes the name of the files and the `v` of a service URL, so no cache keeps the old crop. With `imgproxy` it becomes a `gravity` in the preset. **Bunny** refuses a size anchored away from the center, as it does a class name that does not fit (`thumbs export` and `pollora:doctor` say so): no parameter of a class is known to move the crop it makes to a ratio, and the size would be served centered under a URL that promises another crop.

A size is used by its name, as any WordPress size: `wp_get_attachment_image($id, 'card')`, `the_post_thumbnail('hero')`, `wp_get_attachment_image_src()`.

**The URL of a size is worked out, never read.** It comes from the metadata of the original (its file, width and height), whether the file of the size exists or not: `photo-card-600x400-{signature}.webp`. The signature is an HMAC of the original and of the parameters of the size, cut to 12 characters (the application key in Pollora, a salt of WordPress otherwise): changing a size changes its name, so no cache holds a stale file. The `width`, `height` and `srcset` WordPress writes come from the same computation, with no call to the disk.

The sizes WordPress registers itself (`add_image_size()`, a theme's `config/images.php`, WooCommerce, the core sizes) are left as they are, with the names WordPress gives them, so a theme keeps its output until it declares sizes of its own. A declared size of the same name replaces the WordPress one. A GIF is never resized, which would stop its animation: its sizes are the original.

### Sizes made on demand

A size is made at upload only if it is declared `eager()`. The same goes for an image cropped, rotated or flipped in the media library: its new original gets its `eager()` sizes, and the files of the previous original stay for *Restore image*. The editor's *Apply changes to: thumbnail only* option is not followed by a declared size, which is always made from the original. The others are made the first time their URL is asked for: the web server finds no file, sends the request to PHP, and the module makes the size at the exact path of its URL and answers with it. The next request finds the file where the URL said, and the web server or the CDN serves it without PHP. The URL never changes, so there is nothing to regenerate when a size is added or changed.

- **Local disk.** Nginx (`try_files $uri /index.php`) and Apache (the `!-f` rule to `index.php`) already send a missing file to PHP: nothing to set up. For Apache, add the long cache header to the library's `.htaccess` so files already made carry it too.
- **S3 or compatible service behind a CDN.** The origin of the CDN for the library is the site: one rule at the CDN. PHP sees each file the first time the CDN asks, then the cache lasts a year.
- **S3 without a CDN.** There is no fallback: declare the sizes you use `eager()` and run `thumbs warm`. The URL stays the same.

What the URL says is not trusted. First, without asking the disk: the name is one of a declared size, the signature is the one the site would have made, the extension is an image's, and the path does not climb out of the library. Then, against the original: its dimensions are those the name gives (nobody can name a size of their own), it is not private, it is not a GIF. A request that fails a check gets a 404 and costs nothing.

Making a size costs CPU, so it is limited, per server: one process per file (ten simultaneous requests for the same missing size make one), at most `POLLORA_MEDIA_THUMBNAILS_CONCURRENCY` sizes at the same time (4), and an original above `POLLORA_MEDIA_THUMBNAILS_MAX_PIXELS` (50 million pixels, imgproxy's default) or `POLLORA_MEDIA_THUMBNAILS_MAX_BYTES` (30 MiB) gets no size at all: it stays downloadable. A request that cannot get a place within `POLLORA_MEDIA_THUMBNAILS_WAIT` seconds (10) is handed a size that already exists, the smallest that is at least as wide, the original at worst, with `Cache-Control: no-store`: the visitor sees an image, the CDN does not keep the stand-in, and the real size is made on a later request. The answer for a size made is `200` with `Cache-Control: public, max-age=31536000, immutable`, never a redirect.

An image uploaded before a size was declared has no file for it yet. Make them with the commands below, before a heavy page goes live:

```bash
wp pollora media thumbs warm --size=card --since=2026-01-01   # the missing files, for the images already uploaded
wp pollora media thumbs prune                                 # dry run: the files of a size that was changed or removed
wp pollora media thumbs prune --execute
wp pollora media thumbs export --driver=bunny                 # with a service that makes the sizes: see below
```

`prune` only considers files named the way the module names its sizes, next to an original that is still there: whatever WordPress or a plugin made is left alone.

The format needs an image editor that can write it: GD and Imagick write WebP; AVIF depends on the server. A size that cannot be written is logged and left out of the metadata.

### Sizes made by a service

By default the module makes the sizes itself. For a very large library, an image service can make them instead: the URL of a size changes shape, the declaration of the sizes does not. Set `POLLORA_MEDIA_THUMBNAILS_DRIVER` (`media.thumbnails.driver` with Laravel).

| Driver | URL of a size | Defined at the service by |
|---|---|---|
| `pollora` (default) | `photo-card-600x400-{signature}.webp`, a file of the library | the module |
| `bunny` | `photo.jpg?class=card&v=3f9a1c2b`, an [image class](https://bunny.net/docs/optimizer/image-classes) of Bunny Optimizer | `thumbs export --driver=bunny` |
| `imgproxy` | `{imgproxy}/{signature}/card/plain/{source}@webp?v=3f9a1c2b`, signed, in presets-only mode | `thumbs export --driver=imgproxy` |

`v` is a short tag that changes when the size does (its fit, dimensions, format, quality or upscaling). The service ignores it, but a CDN keys its cache on it: without it, a class or a preset that was changed keeps being served as it was under the same URL (checked on a real Bunny pull zone), where the files of the module change name. With one of them, no file is made (neither at upload nor on demand), there is no fallback route, `thumbs warm` has nothing to do, and a size still has a single URL, built from the metadata of the original. `widths()` become one class or preset per width (`hero-640w`).

**A private media never goes to the service.** Its sizes are not asked of it: WordPress serves the original, signed, as for any private media.

**Bunny.** `media.url` must be the pull zone that serves the library, with the Dynamic Image API enabled. Bunny accepts only letters and digits in the name of a class, so the class of `hero-640w` is `hero640w` and that of `my_size` is `mysize`; two sizes whose classes would be the same cannot both be served, and the second is refused (`thumbs export` and `pollora:doctor` say so). Forcing classes (*Force Transformation Classes*) is up to you; checked on a real pull zone, with it on a valid class is served, an unknown class or a raw transformation gets a 403, and the original, asked with no parameter, is still served. Bunny has no cover mode, so a `cover()` size is a crop to the ratio and then a width (`aspect_ratio` and `width`): the dimensions served are the ones the module advertises, for an original that is big enough as for one that is too small, give or take one pixel: when the ratio of the original does not divide exactly, Bunny rounds the height and then takes the width back to the integer below (1199 pixels for a size of 1200). imgproxy serves exactly the advertised dimensions. AVIF is encoded up to 4 megapixels, then Bunny falls back to another format.

**imgproxy** (4.0.x). Set `IMGPROXY_KEY` and `IMGPROXY_SALT` (hexadecimal) to the values of `POLLORA_MEDIA_IMGPROXY_KEY` and `_SALT`, and `IMGPROXY_SIGNATURE_SIZE` to `POLLORA_MEDIA_IMGPROXY_SIGNATURE_SIZE` if it is not 32. The source is the URL of the library (`url`, the default; imgproxy must be allowed to fetch it) or the object of the bucket (`s3`, with `IMGPROXY_USE_S3`). imgproxy refuses a source on the loopback address by default (a `media.url` of `localhost`): for local development only, start it with `IMGPROXY_ALLOW_LOOPBACK_SOURCE_ADDRESSES=true`; `thumbs export` warns about it. A size cannot be named `default`, which imgproxy applies to every image: it is left to WordPress. A badly set up driver is logged and the default driver is kept.

```bash
wp pollora media thumbs export --driver=bunny             # dry run: the classes that would be created or updated
wp pollora media thumbs export --driver=bunny --execute   # write them to the pull zone
wp pollora media thumbs export --driver=imgproxy          # the configuration to give imgproxy
```

For Bunny, the classes are read, merged by name and written back: the ones you made are kept, nothing is deleted, and the forced classes setting is never changed. The merge matters: Bunny replaces the whole list of classes with the one it is sent. The access key is only ever sent in the `AccessKey` header. imgproxy has no API for presets: the command prints `IMGPROXY_PRESETS`, `IMGPROXY_ONLY_PRESETS=true`, `IMGPROXY_MAX_SRC_RESOLUTION` (in megapixels) and `IMGPROXY_ALLOWED_SOURCES`, never the key or the salt.

## Private media

A media is private when its post meta `_pollora_media_private` is `1`, or when the `pollora/media/is_private` filter says so. A private media is served through a signed URL (`Flysystem`'s `temporaryUrl()`: an S3 pre-signed URL) that expires after `POLLORA_MEDIA_PRIVATE_EXPIRY` (`+6 hours` by default). The flag is read with the attachment and asked once per media in a request: the signature is computed for private media only, and no call to the disk is made to tell a private media from a public one.

The module gives no screen to mark a media private. Three ways to do it:

```php
// 1. From code: every file of the media changes on the disk, then the media itself
\Pollora\Media\Media::register()->privateMedia()->setPrivate($attachmentId, true);  // without Laravel; in Pollora, resolve WordPressMedia from the container

// 2. By rule, for every media: a media created private is private on the disk from the start
add_filter('pollora/media/is_private', fn (bool $private, int $id) => get_post_meta($id, 'invoice', true) !== '', 10, 2);
```

```bash
# 3. From the command line
wp pollora media visibility 42 --private
wp pollora media visibility 42 --public
wp pollora media files 42
```

Hooks to build on it:

| Hook | Role |
|---|---|
| `pollora/media/is_private` (filter) | `(bool $private, int $attachmentId)`: the visibility of a media |
| `pollora/media/signed_url` (filter) | `(string $url, int $attachmentId)`: the final signed URL, for a CDN with its own signature |
| `pollora/media/attachment_files` (filter) | `(array $paths, int $attachmentId)`: files kept next to a media, which follow it when it is deleted or changes visibility |
| `pollora/media/stored` (action) | `($path, $size)`: a file is on the disk |
| `pollora/media/deleted` (action) | `($attachmentId, $paths)`: a media and its files were deleted |
| `pollora/media/visibility_changed` (action) | `($attachmentId, $private)`: a media changed visibility. Used to audit changes or purge a CDN, in a package of its own |

A disk that cannot sign a URL (a plain local disk) serves the plain URL of a private media, rather than a broken one: a private media needs an object store, or a signature of your own through `pollora/media/signed_url`.

## Commands

```bash
wp pollora media status                       # the public URL, and a test write
wp pollora media migrate                      # dry run: what would be copied from the uploads folder
wp pollora media migrate --execute            # copy the files; the source is never changed
wp pollora media migrate --execute --exclude='woocommerce_uploads/*,wc-logs/*'   # leave folders out
wp pollora media migrate --execute --replace-urls --old-url=https://site.test/content/uploads
wp pollora media files <id>                   # the files of a media: original, sizes, declared files
wp pollora media visibility <id> --private    # or --public
```

`migrate` skips a file already on the target with the same size, so it can be repeated. Each file keeps the visibility it has on the source, and carries the `cache_control` header of the configuration. `--exclude` takes comma-separated patterns (`*` matches across folders, so `woocommerce_uploads/*` leaves out the whole folder): use it for folders of private files, such as protected WooCommerce downloads, logs or form attachments, which a public disk would serve to anyone. The Artisan command takes the option once per pattern: `--exclude='woocommerce_uploads/*' --exclude='wc-logs/*'`. `--replace-urls` replaces the old base URL in post content and excerpts and in post, term and user meta and options, serialized values and the JSON of blocks included; the `guid` is left alone. Without `--execute`, nothing is written.

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
