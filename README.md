# BMP Support by Christopher Ross

Enable BMP uploads in WordPress and non-destructively re-encode them to a web-safe format (PNG or WebP), with safe backups and one-click restore.

BMP Support is part of the thisismyurl.com image-plugin family. It shares the same Optimize / Settings / Report admin shell as WebP, HEIC, and SVG Support, so they all work the same way — the difference is the format each one handles.

## What it does

- Enables `.bmp` uploads, even where a host or another plugin has disabled them, with a real-MIME guard so genuine BMP files are never rejected by WordPress file-type checks.
- Re-encodes each BMP to a web-safe format using the WordPress image editor stack (GD or Imagick). Choose PNG (lossless, the default) or WebP (smaller files).
- Keeps every original under `uploads/bmp-backups/` with one-click restore, individually or in bulk.
- Optimize-on-upload, plus optional background auto-optimize via wp-admin traffic and/or WP-Cron.
- Optional EXIF / GPS / metadata stripping and site-credit XMP embedding on the output (requires Imagick).
- A business ROI report across 30-day, 90-day, 12-month, and all-time windows.
- A shared Vault / Shadow backup adapter: when either shared backup engine is active, a safety snapshot is taken before each destructive operation. The plugin's own per-file backup runs regardless.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- GD or Imagick (the metadata features require Imagick)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Go to **Tools > BMP Support**, choose your optimize target, and run optimization.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

Built by [Christopher Ross](https://thisismyurl.com/).
