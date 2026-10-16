# Changelog

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
