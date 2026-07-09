=== BMP Support by Christopher Ross ===
Contributors: thisismyurl
Plugin URI: https://thisismyurl.com/thisismyurl-bmp-support/
Author: Christopher Ross
Author URI: https://thisismyurl.com/
Donate link: https://github.com/sponsors/thisismyurl
Tags: bmp, images, media, optimization, conversion
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6190.1660
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable BMP uploads and non-destructively re-encode them to a web-safe format (PNG or WebP) with safe backups and one-click restore.

== Description ==

BMP Support by thisismyurl.com makes the bitmap (BMP) format a first-class citizen in your Media Library. It does two things: it lets BMP files upload cleanly even when your host or another plugin has disabled them, and it re-encodes each BMP to a web-friendly format using the WordPress image editor stack (GD or Imagick). Originals are preserved in a backup directory under `uploads/bmp-backups/` and can be restored at any time, individually or in bulk.

What this plugin ships:

* Tools > BMP Support page with Optimize, Settings, and Report tabs.
* BMP upload support via `upload_mimes` plus a real-mime guard so genuine BMP files are never rejected by WordPress file-type sniffing.
* Choice of optimize target: PNG (lossless, the safe default) or WebP (smaller files).
* Configurable WebP quality (0-100, used only when the target is WebP) and AJAX batch size (1-100).
* Non-destructive batch optimization with a progress bar and cancel.
* Optimize-on-upload, plus optional background auto-optimize via wp-admin traffic and/or WP-Cron.
* Single Restore button per managed image and a Restore All bulk action that returns the original BMP.
* Re-encode from Originals bulk action: re-processes all previously converted images through the current format setting (PNG or WebP) — useful after changing the optimize target.
* Optional EXIF / GPS / metadata stripping and optional site-credit XMP embedding on the output file (requires Imagick).
* A business ROI report across 30-day, 90-day, 12-month, and all-time windows.
* Search and pagination on the Pending and Managed Media tables.
* A soft Vault / Shadow backup integration: when either shared backup engine is active, a safety snapshot is taken before each destructive operation. The plugin's own per-file backup runs regardless.
* Optional backup-folder cleanup on uninstall.

How it works:

1. Go to Tools > BMP Support.
2. On the Settings tab, choose the optimize target (PNG or WebP), quality, batch size, and automation behaviour.
3. On the Optimize tab, click "Optimize All" to process pending BMP attachments in AJAX batches.
4. Use Restore on individual rows, or "Restore All Originals" for a bulk rollback to the original BMP files.

Notes:

* Uses the WordPress image editor stack (GD or Imagick). All image processing is local — no external services, no background network calls.
* Only `image/bmp` attachments are treated as conversion sources. PNG and WebP attachments created elsewhere are flagged as externally managed and never overwritten.
* Backup paths are stored as written so dev/prod database copies can locate originals.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to Tools > BMP Support.
4. Choose your optimize target and run optimization.

== Frequently Asked Questions ==

= Does this delete original BMP images? =
No. Originals are moved to `uploads/bmp-backups/` and can be restored at any time.

= Why convert BMP at all? =
BMP files are uncompressed and very large, and many browsers and CDNs treat them poorly. Re-encoding to PNG or WebP keeps the image lossless or near-lossless while making it web-safe and far smaller.

= PNG or WebP — which target should I pick? =
PNG is the default: it is lossless and supported everywhere. Choose WebP if you want smaller files and your audience uses modern browsers.

= Does this require Imagick? =
No. Conversion works with either GD or Imagick. Metadata stripping and site-credit embedding require Imagick and are skipped silently if it is unavailable.

= My host blocks BMP uploads. Will this fix that? =
Yes. The plugin re-allows the BMP extension and adds a real-mime guard so genuine BMP files pass WordPress's file-type checks.

= What does Re-encode from Originals do, and when would I use it? =
"Re-encode from Originals" re-processes every previously converted image through the current format setting (PNG or WebP). Use it after changing the optimize target on the Settings tab — it replaces existing converted files with a fresh encode from the original BMP backup. No originals are overwritten; the backup copy is only read, never modified.

== Privacy Policy ==

BMP Support by thisismyurl.com does not collect, transmit, or store any personal data. All image processing runs locally on your server using PHP's GD or Imagick library. No data is sent to external servers. Optional UTM parameters appended to outbound links (when enabled on the Settings tab) are standard analytics query strings on user-initiated clicks to thisismyurl.com; no background requests are made.

== Changelog ==

= 1.6190.1620 =
* **New:** Vortops cloud service settings — enter a shared API key once in any thisismyurl plugin and it applies to all of them. BMP conversion works locally; Vortops enables cloud conversion in companion plugins (HEIC Support, WebP Support).
* **New:** Test connection button for Vortops API key.

= 1.6174.1642 =
* Added "Re-encode from Originals" feature: a new button on the Optimize tab sidebar lets you re-process all previously converted images through the current format setting (PNG or WebP). Useful after changing the optimize target — existing converted files are replaced by a fresh encode from the original BMP backup.
* Added AJAX handler `timu_bmp_reencode_originals`: processes up to 20 attachments per request, returns processed / skipped / failed counts and a `done` flag; JS drives repeated calls until all managed images are re-encoded.
* Capability check uses `manage_options` to match the admin-only scope of the bulk re-encode operation.

= 1.6165.0822 =
* Initial release. Mirrors the approved WEBP Support admin shell (Optimize / Settings / Report tabs, batch + single AJAX flow, restore, ROI report) with BMP-specific execution: BMP upload enablement, a PNG/WebP optimize-target setting, and a shared Vault/Shadow backup adapter.

== Upgrade Notice ==

= 1.6165.0822 =
Initial release of BMP Support.
