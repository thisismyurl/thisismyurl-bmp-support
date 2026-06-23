# BMP Support

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://www.php.net/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)](LICENSE)

BMP Support lets WordPress accept `.bmp` uploads and quietly re-encodes them to a web-safe format, keeping the original on hand in case you want it back.

Almost nobody publishes BMP files on purpose. They show up anyway: a scanner default, a screenshot from an old tool, an export from some industrial app that only speaks bitmap. WordPress usually rejects them, and when it doesn't, you end up serving a 12 MB image that should have been 200 KB. This plugin handles that quietly so you don't have to think about it.

BMP Support is part of the thisismyurl.com image-plugin family. It shares the same Optimize / Settings / Report admin shell as WebP, HEIC, and SVG Support, so once you've used one of them, this one works the same way. The only real difference is the format each plugin handles.

## What it does

- Enables `.bmp` uploads, even where a host or another plugin has disabled them. A real-MIME guard checks the actual file so genuine BMP files don't get rejected by WordPress file-type checks.
- Re-encodes each BMP to a web-safe format using the WordPress image editor stack (GD or Imagick). Pick PNG (lossless, the default) or WebP (smaller files).
- Keeps every original under `uploads/bmp-backups/` with one-click restore, either one file at a time or in bulk.
- Optimizes on upload, with optional background auto-optimize driven by wp-admin traffic and/or WP-Cron.
- Strips EXIF/GPS/metadata and embeds a site-credit XMP tag on the output, if you want it (requires Imagick).
- Reports the business ROI across 30-day, 90-day, 12-month, and all-time windows.
- Uses a shared Vault/Shadow backup adapter: when either shared backup engine is active, it takes a safety snapshot before any destructive operation.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- GD or Imagick (the metadata features require Imagick)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Go to **Tools > BMP Support**, choose your optimize target, and run optimization.

## Versioning

Versions follow `X.Yjjj.hhmm` — year, Julian day, 24-hour time of the build.

## About

BMP Support is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
