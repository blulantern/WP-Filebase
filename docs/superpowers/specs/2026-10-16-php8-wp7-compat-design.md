# WP-Filebase 0.4.0 — PHP 8 / WordPress 7 compatibility fork

**Date:** 2026-10-16 · **Status:** approved design · **Scope:** compatibility fork, behaviour preserved

## 1. Goal

Make WP-Filebase 0.3.4.24 (final upstream release, plugin closed on WordPress.org
2017-11-28) run cleanly on PHP 8.1–8.5 and WordPress 6.6–7.x **without changing
what it does**: same data model (`wp_wpfb_cats`, `wp_wpfb_files`,
`wp_wpfb_files_id3`), same options, same templates, same URLs, same admin UI,
same shortcode/widget output. Existing installs upgrade in place.

Secondary goal: the running plugin produces realistic legacy data for the
SecureFilebase Phase 6 importer on the local dev site (WP 7.1.3 / PHP 8.5).

### Non-goals

- Block-editor block, REST API, or media-frame (`wp.media`) uploader.
- Replacing the eval-based template engine or converting every SQL query to
  `$wpdb->prepare()` (only the paths this work touches).
- New features, translation refresh, namespacing/PSR-4, Pro edition.

## 2. Targets and packaging

| Item | Value |
|---|---|
| PHP | `Requires PHP: 8.1`; tested 8.2, 8.5 |
| WordPress | `Requires at least: 6.6`; `Tested up to: 7.1` |
| Version | `0.4.0` (constant `WPFB_VERSION`, header, `readme.txt`) |
| Slug / text domain / option prefix / tables | unchanged (`wp-filebase`, `wpfilebase*`, `wpfb_*`) |
| Repo | `wp-content/plugins/wp-filebase` is a git repo; commit 1 is the pristine 0.3.4.24 zip (sha256 `e9af05ca…c2d2424`) so every change is a reviewable diff from upstream |

On activation after upgrade, the existing `WPFB_Setup::OnActivateOrVerChange('0.3.4.24')`
path runs: `SetupDBTables` (no schema change), `AddOptions`, `AddTpls` →
`WPFB_Admin::ParseTpls()` (re-parses templates), `flush_rewrite_rules()`.

## 3. Findings the design must resolve

Sweep tooling: `php -l`, PHPCompatibilityWP (`testVersion 8.2-`), PHPStan level 1
with WordPress stubs, grep. Full output in the session scratchpad.

**Fatal at parse time** — curly-brace string offsets `$s{0}` (removed PHP 8.0):
34 sites in `Sync`, `Setup`, `Search`, `ProgressReporter`, `Output`, `Item`,
`GetID3`, `File`, `Download`, `Admin`; both `extras/phpthumb.*.php`; 20 files in
`extras/getid3` (1.9.10).

**Fatal at runtime**

- `create_function()` (removed 8.0), 10 sites. Two are load-bearing:
  `wpfb_callback()` (`wp-filebase.php:107`, registers every admin menu and
  `wp_ajax_*` handler) and `WPFB_Core::CreateTplFunc()` (`Core.php:526`, compiles
  every file/category template). Others: `Item::Sort` (171),
  `Item::GetChildFilesFast` (552), `Output.php` 610 & 734, `Search.php` 69,
  `FileListTable.php` 198, `AdmInstallExt.php` 68.
- Non-static `WPFB_Widget::CatTree()` called as `self::CatTree()` (`Widget.php:12`).
- GD returns `GdImage` objects (8.0): phpThumb resource checks; `FileUtils` thumbnails.
- Unguarded references to Pro-only classes `WPFB_ProLib` (`AdminLite.php:247`)
  and `WPFB_RemoteSync` (`Category.php:327`).

**Deprecations that escalate** — dynamic properties (8.2) on `WPFB_Item` (every
DB column is copied onto `$this`, incl. `file_custom_*` and id3 join columns),
`WPFB_Category::$cat_childs/$childs_complete`, `WPFB_File::$info`;
`utf8_encode()` (8.2, `GetID3.php:129`); null passed to string internals (8.1);
PHP4 constructor + removed INI directive in phpThumb; dead `mysql_close()` branch
(`Download.php:685-689`); `error_reporting(0)` and `@` suppression masking.

**WordPress** — jQuery 3 without Migrate breaks `.andSelf()`/`.size()` in the
vendored treeview and DataTables 1.10.10; TinyMCE 3 API in
`tinymce/editor_plugin.js`; CKEditor plugin for an abandoned editor plugin;
SWFUpload + Flash/Silverlight plupload runtimes; Pro update/extension channel
calling `interface.fabi.me` on every update check; `wpfb-load.php` direct loader
(no callers); `header('HTTP/1.x …')` instead of `status_header()`; deprecated
third argument to `add_option()`.

**Security debt in touched paths** — `extract($_POST)` (`AdminGuiFiles.php:72`);
search `LIKE` built from user terms with `trim()` after `esc_sql()`
(`Search.php:60-110`); `thumbnail.php` authenticates with the guessable `name`
parameter.

## 4. Architecture

Approach: **compatibility layer + surgical edits**. One new class holds every
PHP-8 workaround so call sites stay readable and testable; everything else is a
minimal in-place fix; dead code is deleted rather than patched.

### 4.1 `classes/Compat.php` — `WPFB_Compat`

Loaded from `wp-filebase.php` before any hook is registered. All methods static,
no WordPress dependency except where noted, so they are unit-testable.

| Method | Replaces | Contract |
|---|---|---|
| `callback(string $cl, string $fnc): Closure` | `wpfb_callback()` | Returns `function (...$args) { return wpfb_call($cl, $fnc, $args, true); }`. `wpfb_callback()` stays as a thin wrapper for third-party code. |
| `compileTemplate(string $expr): Closure` | `Core::CreateTplFunc()` and the `eval` in `Item::GenTpl()` | `eval('return function ($f, $e = null) { return (' . $expr . '); };')`. Input is the already-parsed expression string produced by `WPFB_TplLib::Parse()` and cached in options — same string, same semantics. Throws `WPFB_TemplateError` (new, extends `RuntimeException`) on `ParseError` instead of a fatal; callers render the message `Template $type :: $tag is invalid` exactly where they already render "does not exist". |
| `isGdImage(mixed $img): bool` | `is_resource($img)` | `$img instanceof GdImage \|\| is_resource($img)`. |
| `firstChar(?string $s): string` | `$s{0}` where `$s` may be null/empty | Returns `''` for null/empty; otherwise `$s[0]`. Sites where the string is provably non-empty just use `$s[0]`. |
| `statusHeader(int $code): void` | `header('HTTP/1.x …')` | Calls `status_header()` when available, else `http_response_code()`. |
| `thumbSig(int $id, string $kind, string $name): string` | thumbnail `name` secret | `substr(wp_hash("wpfb-thumb|$kind|$id|$name"), 0, 16)`. Used by `Item::GetThumbUrl()`/`GetIconUrl()` and verified in `thumbnail.php` with `hash_equals()`. |

### 4.2 Bootstrap and hooks (`wp-filebase.php`, `AdminLite`, `Core`, `Ajax`)

- `wpfb_callback()` → `WPFB_Compat::callback()`. No change to which hooks are
  registered or their priorities.
- Delete `wpfb-load.php`, the `register_shutdown_function('wpfb_on_shutdown')`
  that lived only there, and `WPFB_Core::InitDirectScriptAccess()`.
- Remove the Pro/update channel: `Core::PreSetPluginsTransientFilter`,
  `Core::PluginsApiFilter`, `classes/ExtensionLib.php`, `classes/AdmInstallExt.php`,
  the "extensions" submenu, the upsell panels in `AdminGuiManage.php`, the
  `WPFB_ProLib` branch in `AdminLite.php`, `ExtensionLib::PluginDeactivated` call in
  `wpfilebase_deactivate()`. `Category::getCloudSync()` returns `null` unless
  `class_exists('WPFB_RemoteSync')`.
- Remove the `is_plugin_active('wp-filebase-pro/…')` auto-deactivation.

### 4.3 Template pipeline

`WPFB_TplLib::Parse()` (string → PHP expression) is unchanged, as are the option
keys `wpfilebase_template_file_parsed`, `…_cat_parsed`, `wpfilebase_ptpls_file`,
`…_ptpls_cat` and the list-template header/footer. `Core::CreateTplFunc()` and
`Item::GenTpl()` both call `WPFB_Compat::compileTemplate()`. `TplLib::Check()`
(admin template editor validation) catches `ParseError`/`Throwable` instead of
relying on `@eval` returning false.

Pre-existing cached expression strings therefore keep working; the version bump
re-parses them anyway.

### 4.4 Item model (`Item`, `File`, `Category`)

- `#[AllowDynamicProperties]` on `WPFB_Item` (the attribute is ignored on PHP
  < 8.2). Custom-field columns `file_custom_*` and id3 join columns are
  legitimately dynamic.
- Declare `public $cat_child_files`, `$cat_childs`, `$childs_complete` on
  `WPFB_Category` and `public $info` on `WPFB_File`.
- `Item::Sort()` comparator becomes a closure over a whitelisted property name
  (`preg_replace('/[^0-9a-z_]/i', '', $on)` kept) using `strcmp((string) …)`.
- `GetChildFilesFast()` walker becomes a static closure.
- `WPFB_Widget::CatTree()` declared `static`.
- Curly-offset sites converted (`$s[0]` or `WPFB_Compat::firstChar()`).

### 4.5 Sync, GetID3, thumbnails

- `Sync::CaptureError()` also returns early for `E_DEPRECATED`/`E_USER_DEPRECATED`
  and `E_USER_NOTICE`; `CaptureException()` logs `Throwable` (covers `TypeError`).
- `extras/getid3` replaced wholesale by getID3 **1.9.27** (PHP 8 clean; same
  `$info` array shape; stored `wpfb_files_id3.value` — base64(serialize($info)) —
  stays readable). `classes/GetID3.php` keeps its include paths; `utf8_encode()`
  → `mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1')`.
- Delete the unused composer `vendor/` tree (getID3 2.0-dev, never loaded) and
  `composer.lock`; `composer.json` becomes dev-only (phpcs + PHPCompatibilityWP,
  phpstan + wordpress-stubs).
- `extras/phpthumb.functions.php` / `phpthumb.bmp.php`: run phpcbf
  (PHPCompatibility auto-fixes), replace `is_resource()` with
  `WPFB_Compat::isGdImage()`, drop the PHP4 constructor alias. `FileUtils` GD code
  reviewed for the same.

### 4.6 Admin JS, editors, uploads

- `extras/jquery/treeview/jquery.treeview-async-edit.js`: `.andSelf()` →
  `.addBack()`, `.size()` → `.length`, `.bind/.unbind` → `.on/.off`; re-minify
  (`terser`) to the `.min.js` the plugin enqueues.
- `extras/jquery/dataTables` → DataTables 1.13.x (same 1.10 API, jQuery 3 safe),
  same handle names `jquery-dataTables`.
- `tinymce/editor_plugin.js` ported to the TinyMCE 4 API
  (`tinymce.PluginManager.add('wpfilebase', function (editor, url) { editor.addButton(…) })`),
  same dialog URL (`admin.php?wpfilebase-screen=editor-plugin`). Registered via the
  existing `mce_external_plugins`/`mce_buttons` filters (classic editor only).
- Delete `extras/ckeditor` and the `ckeditor_external_plugins` block in `AdminLite`.
- Uploads: delete `classes/SWFUpload.php`; `AdvUploader::Create()` always returns
  `WPFB_PLUpload`; plupload init `'runtimes' => 'html5,html4'`, remove
  `flash_swf_url`/`silverlight_xap_url` and the `file_flash_upload` hidden input.
  Core's bundled Plupload 2.1.9 (`plupload`, `plupload-handlers`) remains the
  uploader; the server endpoint `wpfb_action=upload` and its
  `wpfb-async-upload` nonce are unchanged.

### 4.7 Downloads (`Download.php`, `Core::DownloadRedirect`)

- `WPFB_Compat::statusHeader()` for 200/206/304/404.
- Remove the `mysql_close` fallback; keep `$wpdb->close()` (always present on supported WP).
- No change to range handling, traffic accounting, bitrate throttling,
  `Content-Disposition`, referer check, or role checks.

### 4.8 Security fixes in touched paths

- `AdminGuiFiles.php` `extract($_POST)` → explicit `$aa,$mm,$jj,$hh,$mn,$ss`
  read with `(int)` casts.
- `Search.php`: terms trimmed **before** escaping; `LIKE` patterns built with
  `$wpdb->esc_like()` and the final WHERE passed through `$wpdb->prepare()`
  with `%s` placeholders; `$term[0]` guarded for empty terms.
- `thumbnail.php`: the `name` parameter is accepted only when it equals
  `WPFB_Compat::thumbSig()`; URL generators emit the signature. Public files still
  serve thumbnails without it (unchanged behaviour).
- `add_option($n, $v, '', 'no')` instead of the deprecated `null` third argument.
- `wp-filebase.php` no longer calls `error_reporting(0)` anywhere; the download
  path keeps its output-buffer cleanup.

### 4.9 Deleted files

`wpfb-load.php`, `classes/SWFUpload.php`, `classes/ExtensionLib.php`,
`classes/AdmInstallExt.php`, `extras/ckeditor/`, `vendor/`, `composer.lock`,
`bower_components/`, `.travis.yml`, `.idea/`.

## 5. Data-flow impact

| Flow | Change |
|---|---|
| Bootstrap → admin menus / AJAX | callables built by `WPFB_Compat::callback()`; same hooks, caps, slugs |
| Template parse → cache → render | compile step returns a `Closure`; cached strings reused; `ParseError` surfaces as an inline message instead of a fatal |
| Item hydration from DB rows | identical; dynamic props explicitly allowed |
| Sync (FS → DB → getID3 → thumbs) | getID3 1.9.27; GD object checks; error handler ignores deprecations |
| Uploads (Plupload → `wpfb_action=upload`) | HTML5/HTML4 runtimes only |
| Downloads | status codes via `status_header()`; otherwise byte-identical responses |
| Thumbnails | URLs gain `&sig=`; `thumbnail.php` verifies it |
| Update checks | no remote call to `fabi.me` |
| Upgrade from 0.3.4.24 | normal version-change path; no schema migration |

## 6. Error handling

- With `WP_DEBUG` on, the plugin emits **no** notices, warnings or deprecations
  on PHP 8.5 across the smoke suite (acceptance criterion).
- Template compile errors are caught and rendered inline; sync errors keep going
  to the sync log file under the upload dir (`Core::GetLogFile('sync')`) via `Core::LogMsg()`.
- `@` suppressions are left in place except where they hide a `TypeError`
  that the fix removes anyway.

## 7. Testing and gates

Static (run from the plugin dir, tooling via `composer install` dev deps):

1. `find . -name '*.php' -not -path './node_modules/*' | xargs -n1 php -l` — zero failures on PHP 8.5.
2. `phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.1-` — **0 errors**, warnings allowed only inside `extras/getid3`.
3. `phpstan analyse` level 1 with `php-stubs/wordpress-stubs`, `phpVersion 80100` — no "undefined class/method/function"; dynamic-property errors ignored by config.
4. `phpcs --standard=WordPress-Core` on `classes/Compat.php` and the new tests only.

Runtime, on the local site (WP 7.1.3 / PHP 8.5, `WP_DEBUG` + `WP_DEBUG_LOG`,
`debug.log` truncated first; pass = expected behaviour **and** no
`wp-filebase` lines in `debug.log`):

5. Scripted smoke (`php` scripts + `curl`): activate → create category →
   upload a file through the admin form and through the Plupload AJAX endpoint →
   drop a file into `uploads/filebase/<cat>/` and run `WPFB_Sync::Sync()` →
   render `[wpfilebase tag=list]`, `[wpfilebase tag=file id=N]`, the file
   browser, and all five widgets → download (full, `Range: bytes=0-9`,
   `If-Modified-Since` → 304, hit counter +1) → thumbnail with and without a
   valid signature → front-end search → deactivate / reactivate.
6. Playwright (reuse SecureFilebase's `playwright.config.js` runner) visits every
   admin page (`wpfilebase_manage`, `_files`, `_cats`, `_filebrowser`, `_css`,
   `_tpls`, `_sets`) and the classic-editor button; fails on any console error.
7. PHPUnit on wp-env (`.wp-env.json` in the plugin, PHP 8.2 image, WP latest):
   port the five existing `tests/test-*.php` and add unit tests for
   `WPFB_Compat` (callback forwarding, `compileTemplate` output equals the
   0.3.4.24 `create_function` result for the default templates, `firstChar`,
   `thumbSig` stability), `Item::Sort` (asc/desc, injection attempt), search SQL
   escaping (`'`, `%`, `_`, `*`, leading `-`), and `thumbnail.php` signature
   rejection.

## 8. Work breakdown (for the implementation plan)

1. Repo hygiene: delete dead files, dev `composer.json`, `.gitignore`, gates script.
2. `WPFB_Compat` + unit tests.
3. Bootstrap/hooks/Pro-channel removal.
4. Parse-time fixes (curly offsets) across classes + phpThumb; `php -l` green.
5. Template pipeline + `Item`/`Widget` runtime fixes.
6. getID3 1.9.27 swap; Sync/GetID3 fixes.
7. Downloads, thumbnails (signature), search escaping, `extract` removal.
8. JS: treeview patch, DataTables upgrade, TinyMCE 4 port, uploader cleanup.
9. Version bump, readme, CHANGELOG; smoke + Playwright + PHPUnit; tag `v0.4.0`.
