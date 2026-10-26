# Changelog

## Unreleased

### Fixed
- Categories admin page: adding or editing a category with the "Custom Sort Order" box left blank
  (the way the add form renders it) died mid-page on PHP 8 (`0 + ''` TypeError), so categories
  could only be created from the File Browser. Numeric form fields are now cast.
- Categories admin page: the add, update and bulk-delete actions now verify the nonces the forms
  already carried.
- Loading the Sync class (every request with `WP_DEBUG` on, and every upload) no longer replaces
  PHP's error/exception handlers, redirects `error_log` or lifts the time limit; that happens only
  when a scan actually runs (`WPFB_Sync::BeginRun()`). Fatals in admin pages reach `debug.log` and
  WordPress' error screen again instead of vanishing into the sync log.

### Tests
- `tests/smoke/run.php` drives the Categories page over HTTP as the admin (add with blank sort
  order, edit, bulk delete, forged nonce). Playwright signs in through `tests/e2e/auth.setup.js`
  (cookies minted by `tests/e2e/mint-cookies.php` when `WP_PASS` is unset) and runs with
  `reducedMotion: 'reduce'` because WordPress 7.1's cross-document view transitions stall
  headless Chromium after form submits.

## 0.4.0 — 2026-10-16

PHP 8.1–8.5 and WordPress 6.6–7.x compatibility fork of the final upstream
release (0.3.4.24, 2017). Behaviour, data model and templates are unchanged.

### Fixed
- Parse errors from PHP 5 `$str{0}` offsets; `create_function()` removal (hooks, templates, sorting).
- Dynamic property deprecations on items; static call to `WPFB_Widget::CatTree()`.
- GD `GdImage` object handling in thumbnails; `utf8_encode()` removal.
- jQuery 3: `.andSelf()`/`.size()` in treeview and DataTables; TinyMCE 4 editor button.
- HTTP status lines now sent through `status_header()`.

### Changed
- Vendored getID3 upgraded 1.9.10 → 1.9.27.
- Search `LIKE` clauses are prepared/escaped; thumbnail URLs carry an HMAC signature; admin file form no longer `extract()`s `$_POST`.

### Removed
- Pro update/extension channel (remote calls to fabi.me), CKEditor plugin, SWFUpload/Flash/Silverlight uploader paths, `wpfb-load.php`, unused composer `vendor/` tree.
