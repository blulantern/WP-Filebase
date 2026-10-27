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
- File Browser drag & drop works again in current browsers. The tree re-armed its drag handles
  through the `DOMSubtreeModified` mutation event, which Chromium removed in 127 (July 2024), so
  rows loaded when a folder was expanded were never draggable; a `MutationObserver` does it now.
- File Browser: dragging such a not-yet-armed row no longer moves its **parent folder** (the drag
  bubbled to the nearest draggable ancestor). A drag only ever moves the row it started on.
- File Browser: items can be dragged back out to the root (drop on the tree background or its
  "+ Add" row; the tree is outlined while it is the target). Dropping an item where it already is,
  or a folder on itself, does nothing. When the target folder was deleted in another tab the row is
  placed where the server actually put it: the `change-category` response now carries `cat_id`.
  A refused move (expired session) is reported instead of being shown as done; dropping onto a
  folder that was opened and collapsed earlier shows the row instead of losing it until reload; the
  blank part of a folder's "+ Add" row targets that folder, not the root.
- Moving a file out of a folder left the folder's file count unchanged until the next sync (only
  the total was decremented).
- Deleting a category left it in its parent's in-memory child list; the category select rendered
  later in the same request (e.g. after a bulk delete) dereferenced null, and a parent's own
  `Delete()` could re-create the deleted folder.
- The state-changing admin AJAX actions (`delete`, `change-category`, `new-cat`, `attach-file`,
  `set-user-setting`, `toggle-context-menu`) require a nonce (`wpfbConf.nonce`, field `_wpfb_nonce`).
- PHP 8.1 deprecations: `explode()` on a null sort string / unset option, `usleep()` with a float.
- `Undefined array key "post_type"` warning (`Core.php`, `ParseQuery()`) on nearly every front-end
  request: the `parse_query` hook read `query_vars['post_type']`, which WordPress only sets when the
  query asked for a post type. The file-page check now also recognises a list of post types, as REST
  and block queries pass them.

### Tests
- `tests/e2e/filebrowser-dnd.spec.js` drives real HTML5 drags in the admin File Browser (arming
  after expand, parent-folder guard, into a folder and back to the root, sibling no-op, stale
  target, upload through a folder's "+ Add File") against fixtures from `tests/e2e/fixture.php`.
  `tests/smoke/run.php` pins `change-category` persistence server-side (file and folder in, out
  and back, rejected self-move, offline file, missing nonce) and restores PHP's handlers after the
  in-process sync so a later fatal fails the run.
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
