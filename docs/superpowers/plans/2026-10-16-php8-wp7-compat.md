# WP-Filebase 0.4.0 PHP 8 / WP 7 Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make WP-Filebase 0.3.4.24 activate and run without errors, notices or deprecations on PHP 8.1–8.5 and WordPress 6.6–7.x while keeping its data model, templates, URLs and UI unchanged.

**Architecture:** A single new `WPFB_Compat` class carries every PHP-8 workaround (closure factory for hooks, template compiler, GD/status/string helpers, thumbnail signing); every other change is an in-place fix at the exact site that breaks. Dead code (Pro update channel, Flash uploader, CKEditor, direct loader, unused composer tree) is deleted, and vendored getID3 is swapped for the maintained 1.9.27 release.

**Tech Stack:** PHP 8.1+ (dev site runs 8.5.11), WordPress 7.1.3 at `http://127.0.0.1:8090` (started with `filebase/dev-server.sh up`), jQuery 3 (WP core), Plupload 2.1.9 (WP core), TinyMCE 4 (WP classic editor). Tooling borrowed from the sibling plugin: `../secure-filebase/vendor/bin/{phpcs,phpcbf,phpstan,phpunit}` and `../secure-filebase/node_modules/.bin/{terser,playwright}`.

**Spec:** `docs/superpowers/specs/2026-10-16-php8-wp7-compat-design.md`

## Global Constraints

- `Requires PHP: 8.1`, `Requires at least: 6.6`, `Tested up to: 7.1`, version `0.4.0` everywhere (`WPFB_VERSION`, plugin header, `readme.txt` Stable tag).
- Slug `wp-filebase`, text domain `wp-filebase`, option prefix `wpfilebase`, tables `wp_wpfb_cats`/`wp_wpfb_files`/`wp_wpfb_files_id3` — unchanged. No schema migration.
- No behaviour change outside the spec's list: same hooks/priorities, same template option keys and cached expression strings, same download URL formats, same AJAX actions and nonces.
- Every file passes `php -l` on PHP 8.5; PHPCompatibilityWP (`testVersion 8.1-`) reports 0 errors outside `extras/getid3`.
- With `WP_DEBUG`+`WP_DEBUG_LOG`, the smoke suite must leave **no** line containing `wp-filebase` in `wp-content/debug.log`.
- All work happens in the git repo at `filebase/wp-content/plugins/wp-filebase` (branch `main`); one commit per task, message prefixed `feat:`/`fix:`/`chore:`/`test:`, ending with the attribution lines the session reminder gives.
- Run shell commands from the plugin directory: `cd /home/jfox/Projects/filebase/filebase/wp-content/plugins/wp-filebase`. The dev site's `php -S` server **does** see live edits (no restart needed); only wp-env needs restarts.

## Review Focus

1. A stored parsed template containing a syntax error (e.g. an admin saved `<!-- IF %x -->` without `ENDIF` before upgrading) must render an inline "invalid template" message, not a white screen — pinned by `CompatTest::test_compile_template_syntax_error_throws` (Task 3) and `GenTpl2`'s catch (Task 5).
2. A thumbnail URL from a page cached before the upgrade (no `sig` param) for a **public** file must still serve the image; only non-public files require the signature — pinned by `tests/smoke/run.php` step `thumb-public-legacy-url` (Task 9).
3. A search term that is only `-` or only `"` (empty after trimming) must not produce `$term[0]` on an empty string or a `LIKE '%%'` that matches everything — pinned by `SearchSqlTest::test_empty_terms_are_dropped` (Task 7).
4. `Item::Sort()` with an `order_sql` naming a property some items lack (custom field rows mixed with id3 rows) must sort using `''` for missing values, not throw — pinned by `ItemSortTest::test_missing_property_sorts_as_empty` (Task 5).
5. `wpfb_call()` on a class whose file is missing (a Pro-only class name reaching `WPFB_Compat::callback`) must return `null` and log, not fatally `include` — pinned by `CompatTest::test_callback_unknown_class_returns_null` (Task 3).

---

### Task 1: Parse-time fixes — curly-brace string offsets

Make every plugin PHP file (except `extras/getid3`, replaced in Task 6) parse on PHP 8.5.

**Files:**
- Modify: `classes/GetID3.php:219-220`, `classes/Item.php:160,171,384`, `classes/ProgressReporter.php:183`, `classes/Search.php:100`, `classes/Admin.php:738`, `classes/Setup.php:449`, `classes/Output.php:116,119`, `classes/Sync.php:230,1003`, `classes/File.php:114`, `classes/Download.php:602`
- Modify: `extras/phpthumb.bmp.php`, `extras/phpthumb.functions.php` (phpcbf)

**Interfaces:**
- Produces: nothing new; all later tasks assume `php -l` is green for `classes/`, `lib/`, `*.php`, `extras/phpthumb.*`.

- [ ] **Step 1: Record the failing baseline**

Run:
```bash
for f in $(find classes lib extras/phpthumb.bmp.php extras/phpthumb.functions.php extras/progressbar.class.php wp-filebase.php thumbnail.php -name '*.php'); do php -l "$f" >/dev/null 2>&1 || echo "FAIL $f"; done
```
Expected: 12 `FAIL` lines (10 classes + 2 phpthumb files).

- [ ] **Step 2: Replace each `$var{N}` with `$var[N]`**

Apply these exact edits (left = current, right = new):

| File:line | Before | After |
|---|---|---|
| `classes/GetID3.php:219` | `$a = ord($val{0});` | `$a = ord($val[0]);` |
| `classes/GetID3.php:220` | `$val{0} == '?'` | `$val[0] == '?'` |
| `classes/Item.php:160` | `($order_sql{0} == '>')) \|\| $order_sql{0} == '<'` | `($order_sql[0] == '>')) \|\| $order_sql[0] == '<'` |
| `classes/Item.php:384` | `if ($url{0} == '?')` | `if ($url[0] == '?')` |
| `classes/ProgressReporter.php:183` | `$t{0} = strtoupper($t{0});` | `$t = ucfirst($t);` |
| `classes/Search.php:100` | `($term{0} === '-')` | `($term[0] === '-')` |
| `classes/Admin.php:738` | `$opt{0} == '*'` | `$opt[0] == '*'` |
| `classes/Setup.php:449` | `if ($sql{0} == '@') {` | `if ($sql[0] == '@') {` |
| `classes/Output.php:116` | `$sort{0} == '<'` | `$sort[0] == '<'` |
| `classes/Output.php:119` | `$sort{0} == '>'` | `$sort[0] == '>'` |
| `classes/Sync.php:230` | `$fbn{0} == '.'` | `$fbn[0] == '.'` |
| `classes/Sync.php:1003` | `$t{0} = strtoupper($t{0});` | `$t = ucfirst($t);` |
| `classes/File.php:114` | `$o = $field{strlen($field) - 1};` | `$o = substr($field, -1);` |
| `classes/Download.php:602` | `$md5_hash{0} != '#'` | `$md5_hash[0] != '#'` |

`classes/Item.php:171` is left alone here — Task 5 deletes that line when it replaces the `create_function` comparator (the `{$on}` there is string interpolation, not an offset, and parses fine).

- [ ] **Step 3: Auto-fix the two phpThumb files**

Run:
```bash
../secure-filebase/vendor/bin/phpcbf --standard=PHPCompatibility --runtime-set testVersion 8.1- extras/phpthumb.bmp.php extras/phpthumb.functions.php
```
Then open `extras/phpthumb.bmp.php:23` and replace the PHP4 constructor
```php
	function phpthumb_bmp() {
		return true;
	}
```
with
```php
	function __construct() {
		return true;
	}
```
(if phpcbf has already renamed it, leave it). Confirm no `{`-offset remains: `grep -nE '\$[A-Za-z_]+\{' extras/phpthumb.*.php` prints nothing.

- [ ] **Step 4: Verify the lint is green**

Run the Step 1 loop again. Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add classes extras/phpthumb.bmp.php extras/phpthumb.functions.php
git commit -m "fix: replace PHP 5 curly-brace string offsets removed in PHP 8"
```

---

### Task 2: Repo hygiene and gates tooling

Delete code that nothing references, add the dev tooling every later task runs, and give the test suite a bootstrap that works with and without WordPress.

**Files:**
- Delete: `wpfb-load.php`, `vendor/` (whole tree), `composer.lock`, `bower_components/`, `.travis.yml`, `extras/ckeditor/`
- Modify: `classes/Core.php:137-145` (remove `InitDirectScriptAccess`), `classes/AdminLite.php:222-234` (remove CKEditor block)
- Create: `composer.json`, `.gitignore`, `bin/gates.sh`, `phpstan.neon.dist`, `phpcs.xml.dist`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Unit/.gitkeep`, `.wp-env.json`
- Move: `tests/test-*.php`, `tests/create-test-files.php`, `tests/readme.txt` → `tests/Integration/`

**Interfaces:**
- Produces: `bin/gates.sh [lint|compat|stan|unit|all]` — exit 0 on success; `tests/bootstrap.php` defines stub `wp_hash()`, `status_header()`, `esc_sql()`, `__()` when WordPress is absent so `tests/Unit/*Test.php` run standalone.

- [ ] **Step 1: Delete dead files and their two call sites**

```bash
git rm -rq wpfb-load.php vendor composer.lock bower_components .travis.yml extras/ckeditor
mkdir -p tests/Integration tests/Unit
git mv tests/test-create-category.php tests/test-create-file.php tests/test-posttype.php tests/test-sync.php tests/test-tree.php tests/create-test-files.php tests/readme.txt tests/Integration/
```
In `classes/Core.php` delete the whole method `static function InitDirectScriptAccess()` (lines 137–145, the `is_multisite() && !empty($_REQUEST['blog_id'])` block).
In `classes/AdminLite.php` delete lines 222–234: the `if (has_filter('ckeditor_external_plugins')) { ... }` block including its `<script>`.

- [ ] **Step 2: Write `.gitignore`**

```gitignore
/.idea
/nbproject
/node_modules
/vendor
/.phpunit.result.cache
/tests/smoke/out
```

- [ ] **Step 3: Write `composer.json` (dev tooling only)**

```json
{
    "name": "blulantern/wp-filebase",
    "description": "WP-Filebase download manager, PHP 8 / WordPress 7 compatibility fork.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "php-stubs/wordpress-stubs": "^6.6",
        "phpstan/phpstan": "^1.12",
        "squizlabs/php_codesniffer": "^3.10",
        "phpcompatibility/phpcompatibility-wp": "^2.1",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
        "phpunit/phpunit": "^9.6"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    },
    "scripts": {
        "gates": "bin/gates.sh all"
    }
}
```

- [ ] **Step 4: Write `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`**

`phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="WP-Filebase compatibility">
    <description>PHP 8.1+ compatibility gate for WP-Filebase.</description>
    <file>.</file>
    <exclude-pattern>*/extras/getid3/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/tests/smoke/out/*</exclude-pattern>
    <arg name="extensions" value="php"/>
    <arg value="sp"/>
    <config name="testVersion" value="8.1-"/>
    <rule ref="PHPCompatibilityWP"/>
</ruleset>
```

`phpstan.neon.dist`:
```neon
parameters:
    level: 1
    phpVersion: 80100
    paths:
        - classes
        - lib
        - wp-filebase.php
        - thumbnail.php
    scanFiles:
        - %rootDir%/../../php-stubs/wordpress-stubs/wordpress-stubs.php
        - extras/progressbar.class.php
        - extras/phpthumb.functions.php
        - extras/phpthumb.bmp.php
        - extras/getid3/getid3.php
        - extras/getid3/getid3.lib.php
    ignoreErrors:
        - '#Access to an undefined property WPFB_(Item|File|Category)::\$#'
        - '#Variable \$[a-z_]+ might not be defined#'
        - '#Constant (ABSPATH|WPINC|WP_CONTENT_DIR|WP_CONTENT_URL|ARRAY_A|AUTH_COOKIE|SECURE_AUTH_COOKIE|LOGGED_IN_COOKIE|HOUR_IN_SECONDS|MINUTE_IN_SECONDS|WPFB_[A-Z_]+) not found#'
    reportUnmatchedIgnoredErrors: false
```
(The `scanFiles` path resolves inside whichever `vendor` runs phpstan; `bin/gates.sh` passes `--autoload-file` so the sibling plugin's stubs are found.)

`phpunit.xml.dist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" convertDeprecationsToExceptions="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory prefix="test-" suffix=".php">tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Write `tests/bootstrap.php`**

```php
<?php
/**
 * Unit suite: no WordPress — stub the handful of WP functions the compat code calls.
 * Integration suite: WP_TESTS_DIR (wp-env) present — boot the WordPress test library.
 */
$wp_tests = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');

if (is_string($wp_tests) && $wp_tests !== '' && is_dir($wp_tests)) {
    define('WP_PLUGIN_DIR', dirname(__DIR__, 2));
    require_once $wp_tests . '/includes/functions.php';
    tests_add_filter('muplugins_loaded', function () {
        if (!defined('NONCE_SALT')) define('NONCE_SALT', 'test');
        require dirname(__DIR__) . '/wp-filebase.php';
        add_action('init', function () {
            wpfb_loadclass('Core', 'Setup');
            WPFB_Setup::OnActivateOrVerChange(null);
            WPFB_Core::$settings = (object) get_option(WPFB_OPT_NAME);
            WPFB_Core::InitClass();
        }, 1);
    });
    require $wp_tests . '/includes/bootstrap.php';
    return;
}

// ---- Unit mode -------------------------------------------------------------
define('WPFB', 'wpfb');
define('WPFB_VERSION', '0.4.0-test');
define('WPFB_PLUGIN_ROOT', dirname(__DIR__) . '/');
define('WPFB_OPT_NAME', 'wpfilebase');
define('WPFB_PLUGIN_NAME', 'WP-Filebase');

if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth') { return hash_hmac('md5', $data, 'unit-test-salt'); }
}
if (!function_exists('status_header')) {
    $GLOBALS['wpfb_test_status'] = null;
    function status_header($code, $description = '') { $GLOBALS['wpfb_test_status'] = (int) $code; }
}
if (!function_exists('esc_sql')) {
    function esc_sql($s) { return is_array($s) ? array_map('esc_sql', $s) : addslashes($s); }
}
if (!function_exists('__')) {
    function __($t, $d = 'default') { return $t; }
}
if (!function_exists('home_url')) {
    function home_url($p = '') { return 'http://example.test/' . ltrim($p, '/'); }
}

// Minimal class loader mirroring wp-filebase.php's wpfb_loadclass() without echo.
function wpfb_loadclass($cl)
{
    if (func_num_args() > 1) { return array_map(__FUNCTION__, func_get_args()); }
    $cln = 'WPFB_' . $cl;
    if (class_exists($cln)) return true;
    $p = WPFB_PLUGIN_ROOT . "classes/{$cl}.php";
    if (!is_file($p)) return false;
    include_once $p;
    return class_exists($cln);
}
function wpfb_call($cl, $fnc, $params = null, $is_args_array = false)
{
    $cln = 'WPFB_' . $cl;
    if (!class_exists($cln) && !wpfb_loadclass($cl)) return null;
    $fnc = array($cln, $fnc);
    return $is_args_array ? call_user_func_array($fnc, $params) : call_user_func($fnc, $params);
}
require_once WPFB_PLUGIN_ROOT . 'classes/Compat.php';
```
(`classes/Compat.php` does not exist until Task 3; create an empty placeholder file now containing only `<?php` so the bootstrap loads, Task 3 overwrites it.)

- [ ] **Step 6: Write `bin/gates.sh`**

```bash
#!/usr/bin/env bash
# Quality gates for WP-Filebase. Usage: bin/gates.sh [lint|compat|stan|unit|all]
set -euo pipefail
cd "$(dirname "$0")/.."
TOOLS="${WPFB_TOOLS:-$PWD/../secure-filebase/vendor/bin}"
STUBS="${WPFB_STUBS:-$PWD/../secure-filebase/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php}"
AUTOLOAD="${WPFB_AUTOLOAD:-$PWD/../secure-filebase/vendor/autoload.php}"

lint() {
  echo "== php -l"
  local fail=0
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "FAIL $f"; fail=1; }
  done < <(find . -name '*.php' -not -path './node_modules/*' -not -path './vendor/*' -not -path './tests/smoke/out/*' -print0)
  [ "$fail" -eq 0 ] && echo "lint OK"
  return $fail
}
compat() {
  echo "== PHPCompatibilityWP 8.1-"
  "$TOOLS/phpcs" -q --report=summary . && echo "compat OK"
}
stan() {
  echo "== PHPStan level 1"
  sed "s#%rootDir%/../../php-stubs/wordpress-stubs/wordpress-stubs.php#$STUBS#" phpstan.neon.dist > /tmp/wpfb-phpstan.neon
  "$TOOLS/phpstan" analyse -c /tmp/wpfb-phpstan.neon --autoload-file="$AUTOLOAD" --memory-limit=2G --no-progress && echo "stan OK"
}
unit() {
  echo "== PHPUnit unit suite"
  "$TOOLS/phpunit" --testsuite unit
}
case "${1:-all}" in
  lint) lint ;; compat) compat ;; stan) stan ;; unit) unit ;;
  all) lint && compat && stan && unit ;;
  *) echo "usage: $0 [lint|compat|stan|unit|all]"; exit 2 ;;
esac
```
`chmod +x bin/gates.sh`.

- [ ] **Step 7: Write `.wp-env.json` (for the integration suite, Task 9)**

```json
{
	"core": "WordPress/WordPress#7.1",
	"phpVersion": "8.3",
	"plugins": [ "." ],
	"port": 8890,
	"testsPort": 8891,
	"config": {
		"WP_DEBUG": true,
		"WP_DEBUG_LOG": true,
		"SCRIPT_DEBUG": true
	}
}
```

- [ ] **Step 8: Run the gates that can pass now**

Run: `bin/gates.sh lint` → Expected: `lint OK` (getID3 1.9.10 still fails `php -l`; confirm the FAIL lines are all under `extras/getid3/` and nothing else — Task 6 replaces them).
Run: `bin/gates.sh unit` → Expected: `No tests executed!` exit 0 (PHPUnit 9 returns 0 for an empty suite) — this proves the bootstrap loads.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: remove dead loader/vendor/CKEditor code, add gates tooling and test bootstrap"
```

---

### Task 3: `WPFB_Compat` class with unit tests

**Files:**
- Create: `classes/Compat.php`
- Create: `tests/Unit/CompatTest.php`

**Interfaces:**
- Produces (all `public static`):
  - `WPFB_Compat::callback(string $cl, string $fnc): Closure` — closure forwarding all args to `wpfb_call($cl, $fnc, $args, true)`.
  - `WPFB_Compat::compileTemplate(string $expr): Closure` — `function ($f, $e = null)` returning the evaluated expression; throws `WPFB_TemplateError` on `ParseError`.
  - `WPFB_Compat::isGdImage(mixed $img): bool`
  - `WPFB_Compat::firstChar(?string $s): string`
  - `WPFB_Compat::statusHeader(int $code): void`
  - `WPFB_Compat::thumbSig(int $id, string $kind, string $name): string` (16 hex chars)
  - `class WPFB_TemplateError extends RuntimeException`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/CompatTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class WPFB_CompatTestTarget
{
    public static $calls = array();
    public static function Ping() { self::$calls[] = func_get_args(); return 'pong'; }
}

final class CompatTest extends TestCase
{
    public function test_callback_forwards_all_arguments_and_returns_result(): void
    {
        $cb = WPFB_Compat::callback('CompatTestTarget', 'Ping');
        $this->assertInstanceOf(Closure::class, $cb);
        $this->assertSame('pong', $cb(1, 'two', array(3)));
        $this->assertSame(array(array(1, 'two', array(3))), WPFB_CompatTestTarget::$calls);
    }

    public function test_callback_unknown_class_returns_null(): void
    {
        $cb = WPFB_Compat::callback('DoesNotExist', 'Anything');
        $this->assertNull($cb('x'));
    }

    public function test_compile_template_matches_legacy_create_function_semantics(): void
    {
        $f = new stdClass();
        $f->name = 'Report';
        $fn = WPFB_Compat::compileTemplate("'<b>'.\$f->name.'</b>'");
        $this->assertInstanceOf(Closure::class, $fn);
        $this->assertSame('<b>Report</b>', $fn($f));
        $this->assertSame('<b>Report</b>', $fn($f, null));
    }

    public function test_compile_template_passes_extra_data(): void
    {
        $fn = WPFB_Compat::compileTemplate('$e->uid');
        $e = (object) array('uid' => 42);
        $this->assertSame(42, $fn(new stdClass(), $e));
    }

    public function test_compile_template_syntax_error_throws(): void
    {
        $this->expectException(WPFB_TemplateError::class);
        WPFB_Compat::compileTemplate("'unterminated");
    }

    public function test_is_gd_image(): void
    {
        $img = imagecreatetruecolor(2, 2);
        $this->assertTrue(WPFB_Compat::isGdImage($img));
        $this->assertFalse(WPFB_Compat::isGdImage(null));
        $this->assertFalse(WPFB_Compat::isGdImage('string'));
        $this->assertFalse(WPFB_Compat::isGdImage(false));
    }

    public function test_first_char(): void
    {
        $this->assertSame('a', WPFB_Compat::firstChar('abc'));
        $this->assertSame('', WPFB_Compat::firstChar(''));
        $this->assertSame('', WPFB_Compat::firstChar(null));
    }

    public function test_status_header_delegates_to_wordpress(): void
    {
        WPFB_Compat::statusHeader(304);
        $this->assertSame(304, $GLOBALS['wpfb_test_status']);
    }

    public function test_thumb_sig_is_stable_and_distinct(): void
    {
        $a = WPFB_Compat::thumbSig(7, 'file', 'x.jpg');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
        $this->assertSame($a, WPFB_Compat::thumbSig(7, 'file', 'x.jpg'));
        $this->assertNotSame($a, WPFB_Compat::thumbSig(8, 'file', 'x.jpg'));
        $this->assertNotSame($a, WPFB_Compat::thumbSig(7, 'cat', 'x.jpg'));
        $this->assertNotSame($a, WPFB_Compat::thumbSig(7, 'file', 'y.jpg'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bin/gates.sh unit`
Expected: errors `Class "WPFB_Compat" not found` (placeholder file is empty).

- [ ] **Step 3: Write `classes/Compat.php`**

```php
<?php
/**
 * PHP 8 compatibility helpers for WP-Filebase.
 *
 * Every workaround for an API removed or changed in PHP 8 lives here so call
 * sites stay small and the behaviour can be unit-tested without WordPress.
 */

class WPFB_TemplateError extends RuntimeException {}

class WPFB_Compat
{
    /**
     * Replacement for the create_function()-based wpfb_callback().
     * Returns a closure that lazily loads WPFB_{$cl} and forwards every argument.
     */
    public static function callback($cl, $fnc)
    {
        $cl = (string) $cl;
        $fnc = (string) $fnc;
        return static function () use ($cl, $fnc) {
            return wpfb_call($cl, $fnc, func_get_args(), true);
        };
    }

    /**
     * Compile a parsed template expression (output of WPFB_TplLib::Parse) into
     * a closure with the same signature create_function('$f,$e=null', ...) had.
     *
     * @param string $expr PHP expression, e.g. "'<a>'.$f->get_tpl_var('file_name',$e).'</a>'"
     * @return Closure function ($f, $e = null): string
     * @throws WPFB_TemplateError when the expression does not parse.
     */
    public static function compileTemplate($expr)
    {
        $code = 'return static function ($f, $e = null) { return (' . $expr . '); };';
        try {
            $fn = eval($code);
        } catch (ParseError $pe) {
            throw new WPFB_TemplateError(
                sprintf('Template does not compile: %s (line %d)', $pe->getMessage(), $pe->getLine()),
                0,
                $pe
            );
        }
        if (!$fn instanceof Closure) {
            throw new WPFB_TemplateError('Template did not produce a callable.');
        }
        return $fn;
    }

    /** PHP 8 GD returns GdImage objects; PHP 7 returned resources. */
    public static function isGdImage($img)
    {
        return (class_exists('GdImage', false) && $img instanceof GdImage) || is_resource($img);
    }

    /** Safe replacement for $s{0} / $s[0] on possibly null or empty strings. */
    public static function firstChar($s)
    {
        $s = (string) $s;
        return $s === '' ? '' : $s[0];
    }

    /** Send an HTTP status line via WordPress when loaded, else natively. */
    public static function statusHeader($code)
    {
        $code = (int) $code;
        if (function_exists('status_header')) {
            status_header($code);
        } else {
            http_response_code($code);
        }
    }

    /**
     * Signature for thumbnail.php URLs: binds the item id, kind ('file'|'cat')
     * and thumbnail file name to the site's auth salt.
     */
    public static function thumbSig($id, $kind, $name)
    {
        return substr(wp_hash('wpfb-thumb|' . $kind . '|' . (int) $id . '|' . (string) $name), 0, 16);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bin/gates.sh unit`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add classes/Compat.php tests/Unit/CompatTest.php
git commit -m "feat: add WPFB_Compat PHP 8 helper class with unit tests"
```

---

### Task 4: Bootstrap, hooks and Pro-channel removal

Load `WPFB_Compat` first, route every `wpfb_callback()` through it, and delete the extension store / update channel so no admin page reaches `create_function` or `interface.fabi.me`.

**Files:**
- Modify: `wp-filebase.php:99-108,127-133`
- Modify: `classes/Core.php:55-60,535-553`, `classes/AdminLite.php:240-252`, `classes/AdminGuiManage.php:6-20,244-246,284-298,118-131`, `classes/Misc.php:136`, `classes/Category.php:325-331`
- Delete: `classes/ExtensionLib.php`, `classes/AdmInstallExt.php`
- Modify: `classes/Setup.php:617-622` (Pro auto-deactivation)

**Interfaces:**
- Consumes: `WPFB_Compat::callback()` (Task 3).
- Produces: `wpfb_callback($cl, $fnc)` now returns a `Closure` (kept as a public function for third-party code).

- [ ] **Step 1: Write the failing check**

Run: `grep -rn "create_function\|ExtensionLib\|AdmInstallExt\|ProLib\|wpfilebase-pro" wp-filebase.php classes | wc -l`
Expected: a non-empty list (the sites below). Target after this task: nothing except `classes/Item.php`, `classes/Output.php`, `classes/Search.php`, `classes/FileListTable.php` which Tasks 5 and 7 handle.

- [ ] **Step 2: Bootstrap (`wp-filebase.php`)**

Replace lines 99–108 (`function wpfb_callback`) with:
```php
    /**
     * Creates a callback with lazy class autoloading.
     *
     * @param string $cl
     * @param string $fnc
     * @return Closure
     */
    function wpfb_callback($cl, $fnc)
    {
        return WPFB_Compat::callback($cl, $fnc);
    }
```
Directly after `spl_autoload_register('wpfb_autoloadV2');` (line 46) add:
```php
    require_once WPFB_PLUGIN_ROOT . 'classes/Compat.php';
```
In `wpfilebase_deactivate()` delete the line `wpfb_call('ExtensionLib', 'PluginDeactivated');`.

In `wpfb_loadclass()` replace
```php
            $p = WPFB_PLUGIN_ROOT . "classes/{$cl}.php";
            $res = (include_once $p);
            if (!$res) {
```
with
```php
            $p = WPFB_PLUGIN_ROOT . "classes/{$cl}.php";
            $res = is_file($p) ? (include_once $p) : false;
            if (!$res) {
```
so a missing class file (e.g. a Pro-only class) yields the existing error message instead of a PHP warning from `include_once`.

- [ ] **Step 3: Core — remove update channel**

In `classes/Core.php::InitClass()` delete:
```php
        // this adds the wp-filebase update channel
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'PreSetPluginsTransientFilter'));

        // for wpfb-extension details
        add_filter('plugins_api', array(__CLASS__, 'PluginsApiFilter'), 10, 3);
```
and delete the two methods `PreSetPluginsTransientFilter()` and `PluginsApiFilter()` (lines 535–553).

- [ ] **Step 4: AdminLite, AdminGuiManage, Misc, Category, Setup**

`classes/AdminLite.php`: delete the whole `static function pluginUpdateMessage(...)` (lines 240–252) **and** line 52 `add_action('in_plugin_update_message-wp-filebase-pro/wp-filebase.php', array(__CLASS__, 'pluginUpdateMessage'), 10, 2);`, then `grep -n pluginUpdateMessage classes` must print nothing.

`classes/AdminGuiManage.php`:
- Replace the body of `NewExtensionsAvailable()` with `return false;`.
- Delete the `case 'install-extensions':` block (3 lines).
- Delete the Pro nag: the `if (!empty($_REQUEST['wpfb_dismiss_pro_nag'])) {...}` block and the `<div class="notice notice-info">…</div>` that follows it (lines 284–298) — leave the enclosing function returning nothing.
- Delete lines 124–128 (the two `<p style="text-align: center;">… Upgrade to Pro / Live Pro Demo</p>` paragraphs and the sentence `<p>For Cloud support and lots of other advanced features consider an</p>`).

`classes/Misc.php:136`: delete `wpfb_call('ExtensionLib', 'SendStatistics');`.

`classes/Category.php::getCloudSync()` becomes:
```php
    function getCloudSync() {
        if (!class_exists('WPFB_RemoteSync')) return null;
        $rs = WPFB_RemoteSync::GetByCat($this->GetId());
        if($rs) return $rs;
        $parent = $this->GetParent();
        return $parent ? $parent->getCloudSync() : null;
    }
```

`classes/Setup.php`: delete lines 617–622 (the `is_plugin_active` require and the `if (is_plugin_active('wp-filebase-pro/wp-filebase.php')) deactivate_plugins(...)` line).

Then: `git rm classes/ExtensionLib.php classes/AdmInstallExt.php`.

- [ ] **Step 5: Verify**

Run the Step 1 grep → Expected: only `classes/Item.php` (2), `classes/Output.php` (2), `classes/Search.php` (1), `classes/FileListTable.php` (1).
Run: `bin/gates.sh lint` → `lint OK`.
Run: `bin/gates.sh stan 2>&1 | grep -E "ExtensionLib|AdmInstallExt|ProLib|unknown class"` → Expected: no output.

- [ ] **Step 6: Live check on the dev site**

Activate the plugin (idempotent helper, keep it for later tasks):
```bash
php -r '$_SERVER["HTTP_HOST"]="127.0.0.1:8090"; require "/home/jfox/Projects/filebase/filebase/wp-load.php"; require_once ABSPATH."wp-admin/includes/plugin.php"; $r=activate_plugin("wp-filebase/wp-filebase.php"); var_dump(is_wp_error($r)?$r->get_error_message():"activated");'
```
Expected: `string(9) "activated"` (if it reports a template/eval error instead, that is Task 5's job — proceed as long as it is not a `create_function`/parse error).
Then: `curl -s -o /dev/null -w "%{http_code}\n" -b "" http://127.0.0.1:8090/wp-login.php` → `200`, and `grep -c "wp-filebase" ../../debug.log` must not have grown with `create_function` messages (`grep create_function ../../debug.log` → nothing).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: route hook callbacks through WPFB_Compat and remove the dead Pro update channel"
```

---

### Task 5: Template pipeline, Item model, widgets

**Files:**
- Modify: `classes/Core.php:524-527` (`CreateTplFunc`), `classes/Item.php:3-13,157-174,392-443,548-552`, `classes/TplLib.php:104-126` (`Check`), `classes/Category.php:5-26`, `classes/File.php:5-10`, `classes/Widget.php:12`, `classes/Output.php:610,734`, `classes/FileListTable.php:198`
- Create: `tests/Unit/ItemSortTest.php`

**Interfaces:**
- Consumes: `WPFB_Compat::compileTemplate()`, `WPFB_TemplateError`.
- Produces: `WPFB_Core::CreateTplFunc(string $parsed_tpl): Closure` (unchanged name, now a closure).

- [ ] **Step 1: Write the failing sort test**

`tests/Unit/ItemSortTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

final class ItemSortTest extends TestCase
{
    protected function setUp(): void
    {
        wpfb_loadclass('Item');
    }

    private function items(array $names): array
    {
        $out = array();
        foreach ($names as $n) {
            $i = new WPFB_Item();
            $i->file_name = $n;
            $out[] = $i;
        }
        return $out;
    }

    public function test_sort_ascending_by_sql_clause(): void
    {
        $items = $this->items(array('b', 'c', 'a'));
        WPFB_Item::Sort($items, 'file_name ASC');
        $this->assertSame(array('a', 'b', 'c'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_sort_descending_by_arrow_prefix(): void
    {
        $items = $this->items(array('b', 'c', 'a'));
        WPFB_Item::Sort($items, '>file_name');
        $this->assertSame(array('c', 'b', 'a'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_sort_strips_hostile_property_names(): void
    {
        $items = $this->items(array('b', 'a'));
        WPFB_Item::Sort($items, "file_name'); system('id'); //");
        $this->assertSame(array('a', 'b'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_missing_property_sorts_as_empty(): void
    {
        $items = $this->items(array('b', 'a'));
        $items[] = new WPFB_Item(); // no file_name at all
        WPFB_Item::Sort($items, 'file_name ASC');
        $this->assertSame(array('', 'a', 'b'), array_map(fn($i) => (string) ($i->file_name ?? ''), $items));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bin/gates.sh unit`
Expected: `ItemSortTest` errors with `Call to undefined function create_function()`.

- [ ] **Step 3: Item model and sort**

In `classes/Item.php`:

Above `class WPFB_Item` (line 3) add:
```php
#[AllowDynamicProperties]
```

Replace `Sort()` lines 170–172 (`$comparer = …; usort(... create_function ...)`) with:
```php
        $on = preg_replace('/[^0-9a-z_]/i', '', $on); //strip hacking
        $sign = $desc ? -1 : 1;
        usort($items, static function ($a, $b) use ($on, $sign) {
            return $sign * strcmp((string) ($a->$on ?? ''), (string) ($b->$on ?? ''));
        });
```
(keep the existing `$on = preg_replace(...)` line only once.)

Replace in `GetChildFilesFast()`:
```php
        static $parent_walker = false;
        if (!$parent_walker)
            $parent_walker = create_function('&$f,$fid,$pid', 'if($f->file_category != $pid) $f = null;');
```
with:
```php
        static $parent_walker = null;
        if ($parent_walker === null)
            $parent_walker = static function (&$f, $fid, $pid) { if ($f->file_category != $pid) $f = null; };
```

Replace the end of `GenTpl()`:
```php
        self::$tpl_uid++;
        $f = &$this;
        $e = null; // extra data
        return eval("return ($parsed_tpl);");
```
with:
```php
        self::$tpl_uid++;
        try {
            $fn = WPFB_Compat::compileTemplate($parsed_tpl);
        } catch (WPFB_TemplateError $ex) {
            return esc_html(sprintf('Template %s is invalid: %s', $this->is_file ? 'file' : 'cat', $ex->getMessage()));
        }
        return $fn($this, null);
```

In `GenTpl2()` wrap the compile:
```php
            if (empty($parsed_tpl))
                return "Template $type :: $tpl_tag does not exist!";
            try {
                $tpl_funcs[$type][$tpl_tag] = WPFB_Core::CreateTplFunc($parsed_tpl);
            } catch (WPFB_TemplateError $ex) {
                return esc_html("Template $type :: $tpl_tag is invalid: " . $ex->getMessage());
            }
```

- [ ] **Step 4: Core, TplLib, Category, File, Widget, Output, FileListTable**

`classes/Core.php::CreateTplFunc`:
```php
    static function CreateTplFunc($parsed_tpl)
    {
        return WPFB_Compat::compileTemplate($parsed_tpl);
    }
```

`classes/TplLib.php::Check()` body becomes:
```php
	$result = array('error' => false, 'msg' => '', 'line' => '');
	try {
		WPFB_Compat::compileTemplate($tpl);
	} catch (WPFB_TemplateError $e) {
		$result['error'] = true;
		$result['msg'] = $e->getMessage();
		$result['line'] = $e->getPrevious() ? $e->getPrevious()->getLine() : '';
	}
	return $result;
```

`classes/Category.php` after `var $cat_scan_lock = 0;` add:
```php
    var $cat_child_files = null;
    var $cat_childs = null;
    var $childs_complete = false;
```
`classes/File.php` after `var $file_scan_lock = 0;` add `var $info = null;` — then check `File.php:458` still assigns `$this->info` (it does; no change).

`classes/Widget.php:12`: `function CatTree(&$root_cat)` → `static function CatTree(&$root_cat)`.

`classes/Output.php:610`: `add_filter('edit_post_link', create_function('', 'return "";'));` → `add_filter('edit_post_link', '__return_empty_string');`

`classes/Output.php:734`: `create_function('$v', 'return !(is_object($v) || is_array($v));')` → `static function ($v) { return !(is_object($v) || is_array($v)); }`

`classes/FileListTable.php:198`: `create_function('$file', 'return ($file && $file->CurUserCan' . 'Edit' . '());')` → `static function ($file) { return ($file && $file->CurUserCanEdit()); }`

- [ ] **Step 5: Run the tests and gates**

Run: `bin/gates.sh unit` → `OK (13 tests …)`.
Run: `grep -rn "create_function" classes wp-filebase.php` → only `classes/Search.php:69` remains (Task 7).
Run: `bin/gates.sh lint && bin/gates.sh stan` → both OK.

- [ ] **Step 6: Live check — render a template**

```bash
php -r '$_SERVER["HTTP_HOST"]="127.0.0.1:8090"; require "/home/jfox/Projects/filebase/filebase/wp-load.php"; wpfb_loadclass("File","Output"); $files = WPFB_File::GetFiles2(""); $f = reset($files); echo $f ? $f->GenTpl2("default") : "NO FILES YET\n", "\n";'
```
Expected: either `NO FILES YET` (fresh install) or HTML containing the file name — never a PHP error. Also `curl -s http://127.0.0.1:8090/ | grep -c "Fatal"` → `0`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: compile templates to closures, declare dynamic properties, fix static widget call"
```

---

### Task 6: getID3 1.9.27, phpThumb GD objects, Sync error handling

**Files:**
- Replace: `extras/getid3/` with upstream 1.9.27 `getid3/` + `helperapps/` + `license.txt`
- Modify: `classes/GetID3.php:128-129`, `classes/Sync.php:49-56,70`, `extras/phpthumb.bmp.php:732`, `extras/phpthumb.functions.php:239,261`

**Interfaces:**
- Consumes: `WPFB_Compat::isGdImage()`.

- [ ] **Step 1: Fetch and verify upstream getID3**

```bash
S=/tmp/claude-1000/-home-jfox-Projects-filebase/7ba8d6ad-243e-400b-b07b-5689b7af2ca6/scratchpad/getid3-dl
mkdir -p "$S" && cd "$S"
curl -sL -o getid3-1.9.27.zip https://github.com/JamesHeinrich/getID3/archive/refs/tags/v1.9.27.zip
sha256sum getid3-1.9.27.zip   # expected 6fe9aabe794e70336230993799e6294e0cf3443f4303c1bc2176c1a16d060e99
unzip -q -o getid3-1.9.27.zip
grep -m1 "VERSION" getID3-1.9.27/getid3/getid3.php   # expected 1.9.27-202609221015
cd -
```

- [ ] **Step 2: Swap the library**

```bash
git rm -rq extras/getid3
mkdir -p extras/getid3
cp -a "$S"/getID3-1.9.27/getid3/. extras/getid3/
cp -a "$S"/getID3-1.9.27/helperapps extras/getid3/helperapps
cp "$S"/getID3-1.9.27/license.txt extras/getid3/license.txt
rm -rf extras/getid3/demos
ls extras/getid3/getid3.php extras/getid3/getid3.lib.php   # both must exist (paths used by classes/GetID3.php:15,19)
```

- [ ] **Step 3: `classes/GetID3.php` encoding + `classes/Sync.php` handlers**

`classes/GetID3.php:128-129`:
```php
		if (!function_exists('mb_detect_encoding') || mb_detect_encoding($keywords, "UTF-8") != "UTF-8")
			$keywords = function_exists('mb_convert_encoding') ? mb_convert_encoding($keywords, 'UTF-8', 'ISO-8859-1') : $keywords;
```

`classes/Sync.php::CaptureError` first `if` becomes:
```php
        if ($number == E_NOTICE || $number == E_WARNING || $number == E_USER_NOTICE
            || $number == E_DEPRECATED || $number == E_USER_DEPRECATED
        ) {
            return true; // swallowed, as before (false would hand them back to PHP's logger)
        }
```
`CaptureException($exception)` keeps its body; it already accepts any `Throwable` since PHP 7 (no type hint).

- [ ] **Step 4: phpThumb GD checks**

In `extras/phpthumb.bmp.php:732` replace `if (!is_resource($gd)) {` with `if (!WPFB_Compat::isGdImage($gd)) {`.
In `extras/phpthumb.functions.php:239` replace `if (!is_resource($gdimg_hexcolorallocate)) {` with `if (!WPFB_Compat::isGdImage($gdimg_hexcolorallocate)) {`, and at `:261` `if (!is_resource($img)) {` → `if (!WPFB_Compat::isGdImage($img)) {`.
Run `grep -n is_resource extras/phpthumb.*.php classes/FileUtils.php` → expected: no output.

- [ ] **Step 5: Gates**

Run: `bin/gates.sh lint` → `lint OK` with **no** FAIL lines at all now.
Run: `bin/gates.sh compat` → `compat OK` (0 errors; `extras/getid3` is excluded by `phpcs.xml.dist`).
Run: `bin/gates.sh stan` → OK.

- [ ] **Step 6: Live check — analyse a file**

```bash
php -r '$_SERVER["HTTP_HOST"]="127.0.0.1:8090"; require "/home/jfox/Projects/filebase/filebase/wp-load.php"; wpfb_loadclass("GetID3"); $g = WPFB_GetID3::GetEngine(); $i = $g->analyze(ABSPATH."wp-includes/images/w-logo-blue.png"); echo get_class($g), " ", $i["fileformat"] ?? "?", " ", $i["GETID3_VERSION"] ?? "?", "\n";' 2>&1 | tail -3
```
Expected: exactly one line `getID3 png 1.9.27-202609221015` and no `Fatal`/`Deprecated`/`Warning` lines.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: upgrade vendored getID3 to 1.9.27, GD object checks, ignore deprecations in sync error handler"
```

---

### Task 7: Downloads, thumbnail signatures, search escaping, `extract()` removal

**Files:**
- Modify: `classes/Download.php:568,630,644,647,678-690`, `classes/Item.php:462-474`, `thumbnail.php:12-30`, `classes/Search.php:60-118`, `classes/AdminGuiFiles.php:72-80`, `classes/Setup.php:43,49,97-100,155`
- Create: `tests/Unit/SearchSqlTest.php`

**Interfaces:**
- Consumes: `WPFB_Compat::statusHeader()`, `WPFB_Compat::thumbSig()`, `WPFB_Compat::firstChar()`.
- Produces: `WPFB_Search::BuildTermsWhere(array $terms, array $columns, string $p, ?string $id3_column, callable $esc_like, callable $prepare): string` — pure SQL builder used by `SearchWhereSql()`.

- [ ] **Step 1: Write the failing search test**

`tests/Unit/SearchSqlTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

final class SearchSqlTest extends TestCase
{
    private $escLike;
    private $prepare;

    protected function setUp(): void
    {
        wpfb_loadclass('Search');
        // Mirror wpdb::esc_like and a minimal wpdb::prepare for %s.
        $this->escLike = fn($s) => addcslashes($s, '_%\\');
        $this->prepare = function ($sql, ...$args) {
            foreach ($args as $a) {
                $sql = preg_replace('/%s/', "'" . addslashes($a) . "'", $sql, 1);
            }
            return $sql;
        };
    }

    private function build(array $terms, string $p = '%', ?string $id3 = null): string
    {
        return WPFB_Search::BuildTermsWhere($terms, array('t.file_name', 't.file_tags'), $p, $id3, $this->escLike, $this->prepare);
    }

    public function test_plain_term_builds_like_on_each_column(): void
    {
        $sql = $this->build(array('report'));
        $this->assertSame("(1 AND ( (t.file_name LIKE '%report%') OR (t.file_tags LIKE '%report%')) )", $sql);
    }

    public function test_quote_percent_and_underscore_are_escaped(): void
    {
        $sql = $this->build(array("o'neil_50%"));
        // esc_like adds one backslash before _ and %; prepare then escapes ' and doubles backslashes.
        $this->assertStringContainsString("LIKE '%o\\'neil\\\\_50\\\\%%'", $sql);
    }

    public function test_wildcard_star_becomes_percent_only_for_star(): void
    {
        $sql = $this->build(array('rep*rt'));
        $this->assertStringContainsString("LIKE 'rep%rt'", $sql);
    }

    public function test_leading_dash_negates(): void
    {
        $sql = $this->build(array('-draft'));
        $this->assertStringContainsString('AND NOT (', $sql);
        $this->assertStringContainsString("LIKE '%draft%'", $sql);
    }

    public function test_empty_terms_are_dropped(): void
    {
        $sql = $this->build(array('', '-', 'ok'));
        $this->assertSame(1, substr_count($sql, ' AND ('), 'only the real term produces a clause');
        $this->assertStringNotContainsString("LIKE '%%'", $sql);
    }

    public function test_id3_column_added_for_positive_terms_only(): void
    {
        $sql = $this->build(array('x', '-y'), '%', 'i.keywords');
        $this->assertSame(1, substr_count($sql, "i.keywords LIKE"));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bin/gates.sh unit` → `Call to undefined method WPFB_Search::BuildTermsWhere()`.

- [ ] **Step 3: Search — pure builder + call site**

In `classes/Search.php` replace `getSearchTerms()`'s `array_map(create_function(...))` line with:
```php
                $search_terms = array_map(static function ($a) { return trim($a, "\"'\n\r "); }, $matches[0]);
```
and remove the `$s = esc_sql(stripslashes($s));` line two lines above it, replacing it with `$s = stripslashes($s);` (escaping now happens in the builder).

Add the pure builder to the class:
```php
    /**
     * Build the "(1 AND (...) AND NOT (...))" clause for a list of search terms.
     * Pure: every dependency is injected so it can be unit-tested.
     *
     * @param string[] $terms      Raw, trimmed terms; a leading '-' negates, '*' is a wildcard.
     * @param string[] $columns    Fully qualified column names.
     * @param string   $p          '%' for substring match, '' for exact.
     * @param ?string  $id3_column Keywords column to OR in for positive terms, or null.
     * @param callable $esc_like   wpdb::esc_like
     * @param callable $prepare    wpdb::prepare
     */
    public static function BuildTermsWhere(array $terms, array $columns, $p, $id3_column, callable $esc_like, callable $prepare)
    {
        $where = "(1";
        foreach ($terms as $term) {
            $term = (string) $term;
            $not = WPFB_Compat::firstChar($term) === '-';
            if ($not) $term = substr($term, 1);
            if ($term === '') continue;

            $wc = strpos($term, '*') !== false;
            $pattern = $wc ? str_replace('*', '%', $esc_like(str_replace('*', "\0", $term))) : $p . $esc_like($term) . $p;
            $pattern = str_replace("\0", '%', $pattern);

            $where .= $not ? " AND NOT (" : " AND (";
            $or = '';
            foreach ($columns as $col) {
                $where .= " {$or}(" . $prepare("{$col} LIKE %s", $pattern) . ")";
                if ($or === '') $or = 'OR ';
            }
            if (!$not && $id3_column) {
                $where .= " OR (" . $prepare("{$id3_column} LIKE %s", $p . $esc_like($term) . $p) . ")";
            }
            $where .= ") ";
        }
        return $where . ")";
    }
```
Then replace the body of `SearchWhereSql()` from `$search_terms = self::getSearchTerms($s);` to `return $where;` with:
```php
        $search_terms = self::getSearchTerms($s);
        $columns = array();
        foreach ($search_fields as $sf) $columns[] = "{$wpdb->wpfilebase_files}.{$sf}";
        return self::BuildTermsWhere(
            $search_terms,
            $columns,
            $p,
            $search_id3 ? "{$wpdb->wpfilebase_files_id3}.keywords" : null,
            array($wpdb, 'esc_like'),
            array($wpdb, 'prepare')
        );
```
Note: `$wpdb->prepare` with `%s` wraps the value in quotes and escapes it, matching the test's stand-in.

- [ ] **Step 4: Download status codes**

`classes/Download.php`:
- `:568` `header('HTTP/1.x 404 Not Found');` → `WPFB_Compat::statusHeader(404);`
- `:630` `header("HTTP/1.x 304 Not Modified");` → `WPFB_Compat::statusHeader(304);`
- `:644` `header('HTTP/1.0 206 Partial Content');` → `WPFB_Compat::statusHeader(206);`
- `:647` `header('HTTP/1.0 200 OK');` → `WPFB_Compat::statusHeader(200);`
- lines 682–690: replace the whole `if (method_exists($wpdb, 'close')) {...} elseif (function_exists('mysql_close')) {...}` with
```php
        if (method_exists($wpdb, 'close')) {
            $wpdb->close();
        }
```

- [ ] **Step 5: Thumbnail signatures**

`classes/Item.php::GetIconUrl()`:
```php
        if ($this->is_category) {
            // add mtime for cache updates
            return empty($this->cat_icon) ? (WP_CONTENT_URL . WPFB_Core::$settings->folder_icon)
                : home_url("?wpfilebase_thumbnail=1&cid=$this->cat_id&t=" . @filemtime($this->GetThumbPath())
                    . '&sig=' . WPFB_Compat::thumbSig($this->cat_id, 'cat', $this->cat_icon));
        }

        if (!empty($this->file_thumbnail)) {
            return home_url('?wpfilebase_thumbnail=1&fid=' . $this->file_id . '&name=' . rawurlencode($this->file_thumbnail)
                . '&sig=' . WPFB_Compat::thumbSig($this->file_id, 'file', $this->file_thumbnail));
        }
```

`thumbnail.php`: replace the block from `$item = null;` through the `if ($item == null || ...)` condition with:
```php
$item = null;
$kind = null;

if (isset($_GET['fid'])) {
    $item = WPFB_File::GetFile((int) $_GET['fid']);
    $kind = 'file';
} elseif (isset($_GET['cid'])) {
    $item = WPFB_Category::GetCat((int) $_GET['cid']);
    $kind = 'cat';
}

// Non-public items need the HMAC signature minted by WPFB_Item::GetIconUrl().
$thumb_name = $item ? ($kind === 'file' ? (string) $item->file_thumbnail : (string) $item->cat_icon) : '';
$sig_ok = $item && isset($_GET['sig'])
    && hash_equals(WPFB_Compat::thumbSig($item->GetId(), $kind, $thumb_name), (string) $_GET['sig']);

if ($item == null || (!$item->CurUserCanAccess(true) && !$sig_ok)) {
```
(the fallback body that follows is unchanged).

- [ ] **Step 6: `extract($_POST)` and `add_option` cleanup**

`classes/AdminGuiFiles.php:72-80` replace
```php
			extract($_POST);
			if(isset($jj) && isset($ss))
			{
```
with
```php
			$aa = isset($_POST['aa']) ? (int) $_POST['aa'] : 0;
			$mm = isset($_POST['mm']) ? (int) $_POST['mm'] : 0;
			$jj = isset($_POST['jj']) ? (int) $_POST['jj'] : null;
			$hh = isset($_POST['hh']) ? (int) $_POST['hh'] : 0;
			$mn = isset($_POST['mn']) ? (int) $_POST['mn'] : 0;
			$ss = isset($_POST['ss']) ? (int) $_POST['ss'] : null;
			if(isset($jj) && isset($ss))
			{
```
The only `extract()`-sourced variables the rest of that function reads are the six date fields above (`$file_id`, `$action`, `$update`, `$exform` are all assigned earlier in the function), so nothing else changes.

`classes/Setup.php` lines 43, 49, 97–100, 155: change every `add_option(<name>, <value>, null, 'no'/*autoload*/)` to `add_option(<name>, <value>, '', 'no')`.

- [ ] **Step 7: Run tests and gates**

Run: `bin/gates.sh unit` → `OK (19 tests …)`.
Run: `grep -rn "create_function\|HTTP/1\.\|mysql_close\|extract(\$_POST" classes thumbnail.php` → no output.
Run: `bin/gates.sh all` → all OK.

- [ ] **Step 8: Live check — download and search**

```bash
B=http://127.0.0.1:8090
php -r '$_SERVER["HTTP_HOST"]="127.0.0.1:8090"; require "/home/jfox/Projects/filebase/filebase/wp-load.php"; global $wpdb; wpfb_loadclass("Search","File"); $w = WPFB_Search::SearchWhereSql(false, "o'"'"'neil_50% -x"); echo $w, "\n"; var_dump($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->wpfilebase_files WHERE $w")); echo $wpdb->last_error, "\n";'
```
Expected: a WHERE clause with `\'neil\_50\%`, a count (string number), empty `last_error`.
Then with any existing file id N: `curl -s -D - -o /dev/null "$B/?wpfb_dl=N" | head -1` → `HTTP/1.1 200 OK`; with `-H "Range: bytes=0-3"` → `HTTP/1.1 206 Partial Content`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "fix: prepared search LIKE clauses, signed thumbnail URLs, status_header(), no extract(\$_POST)"
```

---

### Task 8: Admin JS, editor button, uploader cleanup

**Files:**
- Modify: `extras/jquery/treeview/jquery.treeview-async-edit.js:63,351,386,394,395,406`; regenerate `extras/jquery/treeview/jquery.treeview-async-edit.min.js`
- Modify: `extras/jquery/dataTables/datatables.min.js` (one token)
- Replace: `tinymce/editor_plugin.js`
- Delete: `classes/SWFUpload.php`
- Modify: `classes/AdvUploader.php:13-15`, `classes/PLUpload.php:54,62-63,80`, `classes/PLUploader.php:95,103-104`

**Interfaces:** none new.

- [ ] **Step 1: Baseline check**

Run: `grep -nE "andSelf|\.size\(\)" extras/jquery/treeview/jquery.treeview-async-edit.js extras/jquery/dataTables/datatables.min.js | wc -l` → `4` lines (3 treeview + 1 DataTables).

- [ ] **Step 2: Patch the treeview source**

In `extras/jquery/treeview/jquery.treeview-async-edit.js`:
- line 63: `.unbind("click.treeview").bind("click.treeview", function(event) {` → `.off("click.treeview").on("click.treeview", function(event) {`
- line 351: `if (!container.children().size())` → `if (!container.children().length)`
- line 386: `.bind("add", function(event, branches) {` → `.on("add", function(event, branches) {`
- line 394: `.find("li").andSelf()` → `.find("li").addBack()`
- line 395: `}).bind("remove", function(event, branches) {` → `}).on("remove", function(event, branches) {`
- line 406: `.siblings(".hitarea").andSelf().remove();` → `.siblings(".hitarea").addBack().remove();`

Re-minify:
```bash
../secure-filebase/node_modules/.bin/terser extras/jquery/treeview/jquery.treeview-async-edit.js -c -m -o extras/jquery/treeview/jquery.treeview-async-edit.min.js
```

- [ ] **Step 3: Patch the DataTables bundle**

```bash
sed -i 's/\.andSelf()/.addBack()/g' extras/jquery/dataTables/datatables.min.js
grep -c "addBack" extras/jquery/dataTables/datatables.min.js   # expected: 1
```
Update the comment header's first line of that file to add ` (patched: andSelf→addBack for jQuery 3)` so the modification is visible.

- [ ] **Step 4: TinyMCE 4 plugin**

Overwrite `tinymce/editor_plugin.js` with:
```js
/* WP-Filebase editor button — TinyMCE 4 plugin API (WordPress 3.9+). */
(function () {
	tinymce.PluginManager.add('wpfilebase', function (editor, url) {
		editor.addButton('wpfbInsertTag', {
			title: 'WP-Filebase',
			image: url + '/images/btn.gif',
			onclick: function () {
				var postId = '';
				try { postId = tinymce.DOM.get('post_ID').value; } catch (ex) {}
				var src = ajaxurl.replace('admin-ajax.php', 'admin.php?wpfilebase-screen=editor-plugin')
					.replace('editor-plugin?', 'editor-plugin&')
					+ '&post_id=' + postId
					+ '&content=' + encodeURIComponent(editor.selection.getContent());
				editor.windowManager.open({
					title: 'WP-Filebase',
					url: src,
					width: Math.min(1000, document.body.clientWidth - 100),
					height: document.body.clientHeight - 100
				}, { plugin_url: url });
			}
		});
	});
})();
```
Confirm `classes/AdminLite.php:182` still registers `$plugins['wpfilebase'] = WPFB_PLUGIN_URI . 'tinymce/editor_plugin.js';` and `MceButtons` adds `wpfbInsertTag` (grep `wpfbInsertTag classes/AdminLite.php`).

- [ ] **Step 5: Uploader cleanup**

`git rm classes/SWFUpload.php`.

`classes/AdvUploader.php::Create()`:
```php
	public static function Create($form_url, $is_edit=false)
	{
		wpfb_loadclass('PLUpload');
		return new WPFB_PLUpload($form_url, $is_edit);
	}
```
`classes/PLUpload.php`: `'runtimes' => 'html5,silverlight,flash,html4',` → `'runtimes' => 'html5,html4',`; delete the two lines `'flash_swf_url' => …` and `'silverlight_xap_url' => …`; delete `<input type="hidden" id="file_flash_upload" name="file_flash_upload" value="0" />`.
`classes/PLUploader.php`: `'runtimes' => 'html5,gears,silverlight,flash,html4',` → `'runtimes' => 'html5,html4',`; delete its `flash_swf_url`/`silverlight_xap_url` lines.
Leave `classes/Admin.php:248-256` (`file_flash_upload` handling) untouched — with the input gone it is simply never set.

- [ ] **Step 6: Verify**

Run: Step 1 grep → `0`.
Run: `grep -rn "SWFUpload\|flash_swf\|silverlight" classes` → no output.
Run: `bin/gates.sh all` → OK.
Live: `curl -s "http://127.0.0.1:8090/wp-content/plugins/wp-filebase/extras/jquery/treeview/jquery.treeview-async-edit.min.js" | grep -c andSelf` → `0`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "fix: jQuery 3 compatible treeview/DataTables, TinyMCE 4 button, drop Flash-era uploader code"
```

---

### Task 9: Version bump, smoke suite, Playwright, release

**Files:**
- Modify: `wp-filebase.php:7,19`, `readme.txt` (header block), `CHANGELOG.md` (create)
- Create: `tests/smoke/run.php`, `tests/smoke/run.sh`, `tests/e2e/admin-pages.spec.js`, `playwright.config.js`, `package.json`

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Version and metadata**

`wp-filebase.php` header: `Version:     0.4.0`, add lines `Requires at least: 6.6`, `Requires PHP:      8.1`; constant `define('WPFB_VERSION', '0.4.0');`.
`readme.txt`: `Stable tag: 0.4.0`, `Tested up to: 7.1`, `Requires at least: 6.6`, `Requires PHP: 8.1`.
Create `CHANGELOG.md`:
```markdown
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
```

- [ ] **Step 2: Smoke suite — `tests/smoke/run.php`**

```php
<?php
/**
 * End-to-end smoke test against the local dev site. Usage:
 *   WP_ROOT=/home/jfox/Projects/filebase/filebase BASE=http://127.0.0.1:8090 php tests/smoke/run.php
 * Exit code 0 only if every step passes AND debug.log has no wp-filebase lines.
 */
$root = getenv('WP_ROOT') ?: '/home/jfox/Projects/filebase/filebase';
$base = getenv('BASE') ?: 'http://127.0.0.1:8090';
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . ':' . (parse_url($base, PHP_URL_PORT) ?: 80);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$fails = 0;
function step($name, $ok, $detail = '') { global $fails; echo ($ok ? "PASS" : "FAIL"), " $name", $detail !== '' ? " — $detail" : '', "\n"; if (!$ok) $fails++; }
function http($url, $headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers));
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return array($code, substr($raw, 0, $hlen), substr($raw, $hlen));
}

$log = WP_CONTENT_DIR . '/debug.log';
file_put_contents($log, '');

// 1. activate
$r = activate_plugin('wp-filebase/wp-filebase.php');
step('activate', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : '');
wp_set_current_user(1);
wpfb_loadclass('Core', 'Admin', 'File', 'Category', 'Sync', 'Output', 'Search', 'Compat');
WPFB_Core::$settings = (object) get_option(WPFB_OPT_NAME);

// 2. category
$cat = WPFB_Admin::InsertCategory(array('cat_name' => 'Smoke ' . time(), 'cat_folder' => 'smoke-' . time()));
step('create category', empty($cat['error']) && !empty($cat['cat']), $cat['error'] ?? '');
$cat_id = $cat['cat']->GetId();

// 3. upload via admin API. InsertFile() only accepts is_uploaded_file() temp files for
//    form uploads, so from the CLI we use the remote-URI branch (same validation, sideloads
//    through WPFB_Download::SideloadFile) pointing at this very site.
$res = WPFB_Admin::InsertFile(array(
    'file_category' => $cat_id,
    'file_remote_uri' => $base . '/license.txt',
    'file_rename' => 'smoke.txt',
));
step('insert file', empty($res['error']) && !empty($res['file']), $res['error'] ?? '');
$file = $res['file'];

// 4. filesystem drop + sync
$drop = dirname($file->GetLocalPath()) . '/dropped.txt';
file_put_contents($drop, "dropped\n");
ob_start(); WPFB_Sync::Sync(false, false); ob_end_clean();
$dropped = WPFB_File::GetByPath($cat['cat']->GetLocalPathRel() . '/dropped.txt');
step('sync picks up dropped file', $dropped !== null && $dropped->is_file);

// 5. renders
$html = do_shortcode('[wpfilebase tag=list id=' . $cat_id . ' /]');
step('shortcode list renders', strpos($html, 'smoke.txt') !== false, substr(strip_tags($html), 0, 120));
$html = do_shortcode('[wpfilebase tag=file id=' . $file->GetId() . ' /]');
step('shortcode file renders', strpos($html, 'smoke.txt') !== false);
$html = $file->GenTpl2('default');
step('GenTpl2 default', strpos($html, 'smoke.txt') !== false);
$c = ''; ob_start(); WPFB_Output::FileBrowser($c, 0, $cat_id); $fb = ob_get_clean() . $c;
step('file browser renders', strpos($fb, 'smoke') !== false);
foreach (array('WPFB_UploadWidget', 'WPFB_AddCategoryWidget', 'WPFB_SearchWidget', 'WPFB_CatListWidget', 'WPFB_FileListWidget') as $w) {
    ob_start(); the_widget($w, array('title' => $w, 'category' => $cat_id)); $out = ob_get_clean();
    step("widget $w renders", is_string($out));
}

// 6. downloads
$dl = $base . '/?wpfb_dl=' . $file->GetId();
list($code, $hdr, $body) = http($dl);
step('download 200', $code === 200 && strlen($body) === filesize($file->GetLocalPath()), "code=$code");
list($code, $hdr) = http($dl, array('Range: bytes=0-3'));
step('download 206', $code === 206 && preg_match('/Content-Range: bytes 0-3\//i', $hdr), "code=$code");
preg_match('/Last-Modified: ([^\r\n]+)/i', $hdr, $m);
list($code) = http($dl, array('If-Modified-Since: ' . $m[1]));
step('download 304', $code === 304, "code=$code");
$hits_before = (int) $file->file_hits; $file = WPFB_File::GetFile($file->GetId());
step('hit counter incremented', (int) $file->file_hits >= $hits_before + 1, "hits={$file->file_hits}");

// 7. thumbnails (a png so a thumbnail gets generated)
$img = WPFB_Admin::InsertFile(array('file_category' => $cat_id, 'file_remote_uri' => $base . '/wp-includes/images/w-logo-blue.png', 'file_rename' => 'smoke.png'));
step('insert image', empty($img['error']) && !empty($img['file']), $img['error'] ?? '');
$imgf = $img['file'];
step('image gets thumbnail', !empty($imgf->file_thumbnail));
$url = $imgf->GetIconUrl();
step('icon url is signed', strpos($url, '&sig=') !== false, $url);
list($code, $hdr) = http(str_replace(home_url(), $base, $url));
step('thumb signed 200', $code === 200 && stripos($hdr, 'X-Fallback-Thumb') === false, "code=$code");
list($code, $hdr) = http(str_replace(home_url(), $base, preg_replace('/&sig=[0-9a-f]+/', '', $url)));
step('thumb-public-legacy-url', $code === 200 && stripos($hdr, 'X-Fallback-Thumb') === false, 'public file without sig still served');
$imgf->file_user_roles = '|administrator'; $imgf->DBSave();
list($code, $hdr) = http(str_replace(home_url(), $base, preg_replace('/&sig=[0-9a-f]+/', '', $url)));
step('thumb private without sig falls back', stripos($hdr, 'X-Fallback-Thumb') !== false);
list($code, $hdr) = http(str_replace(home_url(), $base, $url));
step('thumb private with sig served', stripos($hdr, 'X-Fallback-Thumb') === false);

// 8. search
global $wpdb;
$where = WPFB_Search::SearchWhereSql(false, "smo* -zzz o'neil_1%");
$n = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->wpfilebase_files WHERE $where");
step('search sql executes', $wpdb->last_error === '' && $n !== null, $wpdb->last_error);

// 9. deactivate / reactivate
deactivate_plugins('wp-filebase/wp-filebase.php');
$r = activate_plugin('wp-filebase/wp-filebase.php');
step('reactivate', !is_wp_error($r));

// 10. debug.log must be clean
$bad = array_filter(file($log, FILE_IGNORE_NEW_LINES) ?: array(), fn($l) => stripos($l, 'wp-filebase') !== false);
step('debug.log clean', count($bad) === 0, implode(' | ', array_slice($bad, 0, 3)));

// cleanup
$file->Remove(); $imgf->Remove(); if ($dropped) $dropped->Remove(); $cat['cat']->Delete();
exit($fails === 0 ? 0 : 1);
```
`InsertCategory()` returns `array('error' => false, 'cat_id' => …, 'cat' => WPFB_Category)` on success and `array('error' => '<message>')` on failure; `InsertFile()` returns `array('error' => …, 'file' => WPFB_File)` likewise — the script relies on exactly those keys.

`tests/smoke/run.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
WP_ROOT="${WP_ROOT:-/home/jfox/Projects/filebase/filebase}" BASE="${BASE:-http://127.0.0.1:8090}" php tests/smoke/run.php
```
`chmod +x tests/smoke/run.sh`.

- [ ] **Step 3: Run the smoke suite until green**

Run: `tests/smoke/run.sh`
Expected: every line `PASS`, exit 0. For any `FAIL`, fix the plugin (not the test) in the task that owns that code path and re-run; a `debug.log clean` failure prints the offending lines — each is a real PHP 8 / WP 7 incompatibility to fix.

- [ ] **Step 4: Playwright admin sweep**

`package.json`:
```json
{
  "name": "wp-filebase",
  "version": "0.4.0",
  "private": true,
  "scripts": { "test:e2e": "playwright test" },
  "devDependencies": { "@playwright/test": "^1.49.1" }
}
```
`playwright.config.js`:
```js
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
	testDir: './tests/e2e',
	timeout: 60_000,
	workers: 1,
	use: { baseURL: process.env.WP_BASE_URL || 'http://127.0.0.1:8090', trace: 'retain-on-failure' },
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
```
`tests/e2e/admin-pages.spec.js`:
```js
const { test, expect } = require('@playwright/test');

const PAGES = ['wpfilebase_manage', 'wpfilebase_files', 'wpfilebase_cats', 'wpfilebase_filebrowser', 'wpfilebase_css', 'wpfilebase_tpls', 'wpfilebase_sets'];

test.beforeEach(async ({ page }) => {
	await page.goto('/wp-login.php');
	await page.fill('#user_login', process.env.WP_USER || 'blulantern');
	await page.fill('#user_pass', process.env.WP_PASS);
	await page.click('#wp-submit');
	await expect(page).toHaveURL(/wp-admin/);
});

for (const slug of PAGES) {
	test(`admin page ${slug} has no console errors`, async ({ page }) => {
		const errors = [];
		page.on('pageerror', (e) => errors.push(e.message));
		page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
		const res = await page.goto(`/wp-admin/admin.php?page=${slug}`);
		expect(res.status()).toBe(200);
		await expect(page.locator('#wpbody')).toBeVisible();
		await expect(page.locator('body')).not.toContainText(/Fatal error|Warning:|Deprecated:/);
		expect(errors, errors.join('\n')).toEqual([]);
	});
}

test('classic editor shows the WP-Filebase button', async ({ page }) => {
	await page.goto('/wp-admin/post-new.php?classic-editor');
	const btn = page.locator('.mce-i-wpfbInsertTag, [aria-label="WP-Filebase"], .mce-btn img[src*="btn.gif"]');
	await expect(btn.first()).toBeVisible({ timeout: 15000 });
});
```
Run (reuse the sibling's browsers): `WP_PASS='<admin password>' PLAYWRIGHT_BROWSERS_PATH=$(node -e "console.log(require('path').join(require('os').homedir(),'.cache/ms-playwright'))") ../secure-filebase/node_modules/.bin/playwright test -c playwright.config.js`
Expected: 8 passed. If the classic-editor test cannot find a classic editor (block editor only, no Classic Editor plugin), mark it `test.skip` with the reason in the file and note it in the commit message.

- [ ] **Step 5: Integration suite on wp-env (best effort)**

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wp-filebase bash -c 'WP_TESTS_DIR=/wordpress-phpunit ../secure-filebase/vendor/bin/phpunit --testsuite integration'
npx @wordpress/env stop
```
Expected: the five legacy tests run; `test_new_file_remote` may fail because its fixture URL (`wpfilebase.com`) is dead — if so, change the URL in `tests/Integration/test-create-file.php` to `https://s.w.org/style/images/about/WordPress-logotype-standard.png` and re-run. Record the final result in the commit message; a wp-env infrastructure failure (Docker image pull) does not block the release but must be stated.

- [ ] **Step 6: Final gates and tag**

Run: `bin/gates.sh all && tests/smoke/run.sh` → all OK / all PASS.
```bash
git add -A
git commit -m "chore: release 0.4.0 — PHP 8 / WordPress 7 compatibility, smoke and e2e suites"
git tag -a v0.4.0 -m "WP-Filebase 0.4.0: PHP 8.1–8.5 / WordPress 6.6–7.x compatibility fork"
git log --oneline | head -12
```

---

## Self-review notes

- Spec §4.6 said "DataTables → 1.13.x"; this plan patches the single `andSelf` token in the existing 1.10.10 bundle instead (the bundle includes five extensions whose matching 1.13-era versions would have to be re-assembled, and the only API the plugin calls is `.DataTable()`). The spec is amended to match.
- Spec §4.1 `firstChar()` is used by the search builder; the other 13 curly-offset sites are provably non-empty strings and use `[0]`/`substr` directly.
- Every `WPFB_Compat` method name used in Tasks 4–9 matches Task 3's definitions: `callback`, `compileTemplate`, `isGdImage`, `firstChar`, `statusHeader`, `thumbSig`; `WPFB_Search::BuildTermsWhere` is defined and consumed only in Task 7.
