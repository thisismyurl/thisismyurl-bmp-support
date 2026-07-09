# Changelog

All notable changes to **BMP Support by Christopher Ross** are recorded here. The plugin uses a `X.Yddd.hhmm` Julian-day version scheme: `X` is the release class (`0` = pre-release, `1` = full), `Y` is the last digit of the year, and `ddd` is the day of year (001–366).

## [1.6174.1642] — 2026-06-23

### Added
- "Re-encode from Originals" bulk action: re-processes all previously converted images through the current format setting (PNG or WebP). Useful after changing the optimize target — existing converted files are replaced by a fresh encode from the original BMP backup.
- AJAX handler `timu_bmp_reencode_originals`: processes up to 20 attachments per request with processed/skipped/failed counts and a `done` flag; JS drives repeated calls until all managed images are re-encoded.

## [1.6165.0822] — 2026-06-14

### Added
- Initial release. Mirrors the WEBP Support admin shell (Optimize / Settings / Report tabs, batch AJAX flow, restore, ROI report) with BMP-specific execution: BMP upload enablement, PNG/WebP optimize-target setting, and a shared Vault/Shadow backup adapter.
