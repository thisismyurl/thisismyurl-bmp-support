# Changelog

All notable changes to **BMP Support by Christopher Ross** are recorded here. The plugin uses a `X.Yddd.hhmm` Julian-day version scheme: `X` is the release class (`0` = pre-release, `1` = full), `Y` is the last digit of the year, and `ddd` is the day of year (001–366).

## 1.6190.1660 — 2026-07-09

### Changed
- Suite core refactor — Vortops client and settings UI moved to shared `class-timu-suite-core.php`. Single canonical file synced across all thisismyurl plugins.
- Vortops postbox now rendered by `TIMU_Suite_Settings::render_vortops_postbox()`.

## 1.6190.1620 — 2026-07-09

### Added
- **Vortops cloud services settings** — new postbox in the Settings tab for managing the shared Vortops API key. BMP conversion runs locally on the server and does not require Vortops; the shared key enables cloud conversion in companion TIMU plugins. Enter it once and all thisismyurl plugins use it.
- `TIMU_Vortops_Client` shared client class (`includes/class-timu-vortops-client.php`).
- Test-connection button that validates the API key before saving.

## [1.6174.1642] — 2026-06-23

### Added
- "Re-encode from Originals" bulk action: re-processes all previously converted images through the current format setting (PNG or WebP). Useful after changing the optimize target — existing converted files are replaced by a fresh encode from the original BMP backup.
- AJAX handler `timu_bmp_reencode_originals`: processes up to 20 attachments per request with processed/skipped/failed counts and a `done` flag; JS drives repeated calls until all managed images are re-encoded.

## [1.6165.0822] — 2026-06-14

### Added
- Initial release. Mirrors the WEBP Support admin shell (Optimize / Settings / Report tabs, batch AJAX flow, restore, ROI report) with BMP-specific execution: BMP upload enablement, PNG/WebP optimize-target setting, and a shared Vault/Shadow backup adapter.
