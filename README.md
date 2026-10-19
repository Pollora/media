# pollora/media

WordPress media on any Flysystem disk (local, S3, R2, Scaleway…), behind two settings: the disk and the public URL. It runs with or without Laravel.

> **Work in progress.** Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it will be installed and wired for you. Nothing is released yet; the first version lands with Pollora 13.37.

## Principles

- Two settings, no more: the disk and the public URL. The rest is derived.
- The WordPress core is the integration point (`upload_dir` and a stream wrapper), so Gutenberg, ACF, Meta Box, WooCommerce and imports follow without adaptation.
- A media URL is the public URL plus a relative path, built and never asked to the storage: no disk call while a public page renders.
- Nothing specific to a plugin: extensions are WordPress hooks (`pollora/media/*`).

## License

MIT. See [LICENSE](LICENSE).
