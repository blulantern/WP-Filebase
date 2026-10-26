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
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return array($code, substr($raw, 0, $hlen), substr($raw, $hlen));
}
function site_url_for($url) { global $base; return str_replace(home_url(), $base, $url); }
// Admin session for HTTP-level checks of the admin pages: mint the auth cookies for user 1.
function admin_cookie_header() {
    static $h = null;
    if ($h === null) {
        $exp = time() + DAY_IN_SECONDS;
        $h = 'Cookie: ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie(1, $exp, 'auth') . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie(1, $exp, 'logged_in');
    }
    return $h;
}
function admin_get($url) { return http(site_url_for($url), array(admin_cookie_header())); }
function admin_post($url, array $fields) {
    $ch = curl_init(site_url_for($url));
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => array(admin_cookie_header()),
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields)); // array => multipart/form-data, like the browser
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return array($code, substr($raw, 0, $hlen), substr($raw, $hlen));
}
function form_nonce($html, $field) { return preg_match('/name="' . preg_quote($field, '/') . '" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }

$log = WP_CONTENT_DIR . '/debug.log';
file_put_contents($log, '');

// 1. activate as a FRESH install (options dropped, activation in a separate PHP process so the
//    plugin boots with no settings — see fresh-activate.php), then re-activate in this process.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fresh-activate.php'), $fresh_code);
step('fresh-install activation', $fresh_code === 0, "exit=$fresh_code");
$r = activate_plugin('wp-filebase/wp-filebase.php');
step('activate', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : '');
wp_set_current_user(1);
wpfb_loadclass('Core', 'Admin', 'File', 'Category', 'Sync', 'Output', 'Search', 'Compat');
// Loading the Sync class must not hijack PHP's exception handler on an ordinary request:
// a fatal in an admin page would otherwise vanish into the plugin's sync log instead of debug.log.
$prev_handler = set_exception_handler(null);
step('loading Sync leaves the exception handler alone', !(is_array($prev_handler) && ($prev_handler[0] ?? null) === 'WPFB_Sync'), is_array($prev_handler) ? implode('::', $prev_handler) : '');
WPFB_Core::$settings = (object) get_option(WPFB_OPT_NAME);

// 2. category
$cat = WPFB_Admin::InsertCategory(array('cat_name' => 'Smoke ' . time(), 'cat_folder' => 'smoke-' . time()));
step('create category', empty($cat['error']) && !empty($cat['cat']), $cat['error'] ?? '');
if (empty($cat['cat'])) { echo "cannot continue without a category\n"; exit(1); }
$cat_id = $cat['cat']->GetId();

// 2b. the Categories admin page, over HTTP, exactly as the browser submits it (multipart, the
//     "Custom Sort Order" box left blank the way the add form renders it).
$cats_url = admin_url('admin.php?page=wpfilebase_cats');
list($code, , $html) = admin_get($cats_url);
$nonce = form_nonce($html, 'wpfb-cat-nonce');
step('cats page renders add form', $code === 200 && strpos($html, 'id="addcat"') !== false && $nonce !== '', "code=$code");
$http_cat_name = 'Smoke HTTP ' . time();
list($code, , $html) = admin_post($cats_url, array('action' => 'addcat', 'cat_id' => '0', 'wpfb-cat-nonce' => $nonce,
    'cat_name' => $http_cat_name, 'cat_folder' => '', 'cat_parent' => '0', 'cat_description' => '', 'cat_perm_explicit' => '0', 'cat_order' => '', 'submit' => 'Add New Category'));
$http_cat = null;
foreach (WPFB_Category::GetCats("WHERE cat_name = '" . esc_sql($http_cat_name) . "'") as $c) { $http_cat = $c; }
step('cats page add (blank sort order)', $code === 200 && strpos($html, 'Category added.') !== false && $http_cat !== null, "code=$code len=" . strlen($html));
list($code, , $html) = admin_post($cats_url, array('action' => 'addcat', 'cat_id' => '0', 'wpfb-cat-nonce' => 'deadbeef00',
    'cat_name' => $http_cat_name . ' forged', 'cat_parent' => '0', 'cat_perm_explicit' => '0', 'cat_order' => '0'));
$forged = WPFB_Category::GetCats("WHERE cat_name = '" . esc_sql($http_cat_name . ' forged') . "'");
// The page handles its actions while rendering, after the admin header went out, so wp_die() cannot
// change the status line any more: the rejection shows as WordPress' "link has expired" page.
step('cats page rejects a bad nonce', count($forged) === 0 && ($code === 403 || stripos($html, 'expired') !== false), "code=$code");
foreach ($forged as $c) { $c->Delete(); }
if ($http_cat) {
    list($code, , $html) = admin_get(add_query_arg(array('action' => 'editcat', 'cat_id' => $http_cat->GetId()), $cats_url));
    $nonce = form_nonce($html, 'wpfb-cat-nonce');
    step('cats page renders edit form', $code === 200 && strpos($html, 'id="editcat"') !== false && $nonce !== '', "code=$code");
    list($code, , $html) = admin_post($cats_url, array('action' => 'updatecat', 'cat_id' => (string) $http_cat->GetId(), 'wpfb-cat-nonce' => $nonce,
        'cat_name' => $http_cat_name . ' edited', 'cat_folder' => $http_cat->cat_folder, 'cat_parent' => (string) $cat_id, 'cat_description' => 'edited',
        'cat_perm_explicit' => '1', 'cat_order' => '', 'submit' => 'Update'));
    unset(WPFB_Category::$cache[$http_cat->GetId()]); WPFB_Category::$cache_complete = false; // this process cached the pre-update row
    $edited = WPFB_Category::GetCat($http_cat->GetId());
    step('cats page edit (blank sort order, new parent)', $code === 200 && strpos($html, 'Category updated.') !== false
        && $edited && $edited->cat_name === $http_cat_name . ' edited' && (int) $edited->cat_parent === $cat_id && is_dir($edited->GetLocalPath()),
        "code=$code parent=" . ($edited ? $edited->cat_parent : '?') . ' path=' . ($edited ? $edited->GetLocalPathRel() : '?'));
    list($code, , $html) = admin_get($cats_url);
    list($code, , $html) = admin_post($cats_url, array('deleteit' => 'Delete', 'delete' => array((string) $http_cat->GetId()), '_wpnonce' => form_nonce($html, '_wpnonce')));
    WPFB_Category::$cache_complete = false; unset(WPFB_Category::$cache[$http_cat->GetId()]);
    $gone = count(WPFB_Category::GetCats('WHERE cat_id = ' . (int) $http_cat->GetId())) === 0;
    step('cats page bulk delete', $code === 200 && $gone, "code=$code");
    if (!$gone) { $http_cat->Delete(); }
}

// 3. upload via admin API. InsertFile() only accepts is_uploaded_file() temp files for
//    form uploads, so from the CLI we use the remote-URI branch (same validation, sideloads
//    through WPFB_Download::SideloadFile) pointing at this very site.
$res = WPFB_Admin::InsertFile(array(
    'file_category' => $cat_id,
    'file_remote_uri' => $base . '/license.txt',
    'file_rename' => 'smoke.txt',
));
step('insert file', empty($res['error']) && !empty($res['file']), $res['error'] ?? '');
$file = $res['file'] ?? null;
if (!$file) { echo "cannot continue without a file\n"; exit(1); }

// 4. filesystem drop + sync
$drop = dirname($file->GetLocalPath()) . '/dropped.txt';
file_put_contents($drop, "dropped\n");
ob_start(); WPFB_Sync::Sync(false, false); ob_end_clean();
$dropped = WPFB_File::GetByPath($cat['cat']->GetLocalPathRel() . '/dropped.txt');
step('sync picks up dropped file', $dropped !== null && !empty($dropped->is_file));

// 5. renders
$html = do_shortcode('[wpfilebase tag=list id=' . $cat_id . ' /]');
step('shortcode list renders', strpos($html, 'smoke.txt') !== false, substr(strip_tags($html), 0, 120));
$html = do_shortcode('[wpfilebase tag=file id=' . $file->GetId() . ' /]');
step('shortcode file renders', strpos($html, 'smoke.txt') !== false);
$html = $file->GenTpl2('default');
step('GenTpl2 default', strpos($html, 'smoke.txt') !== false);
$c = ''; ob_start(); WPFB_Output::FileBrowser($c, 0, $cat_id); $fb = ob_get_clean() . $c;
// The browser's category tree is filled by AJAX; the server HTML carries the container + toolbar.
step('file browser renders', strpos($fb, 'wpfb') !== false && strpos($fb, 'Add File') !== false, substr(strip_tags($fb), 0, 80));
// Run each widget through its own update() so the instance has exactly the keys a saved widget has.
$widget_input = array('title' => 'Smoke', 'category' => $cat_id, 'cat' => $cat_id, 'overwrite' => 0, 'attach' => 0,
    'placeholder' => 'Search files', 'hierarchical' => 1, 'sort-by' => 'file_date', 'sort-asc' => 0, 'limit' => 5,
    'tpl' => '<a href="%file_url%">%file_name%</a>');
foreach (array('WPFB_UploadWidget', 'WPFB_AddCategoryWidget', 'WPFB_SearchWidget', 'WPFB_CatListWidget', 'WPFB_FileListWidget') as $w) {
    $widget = new $w();
    $instance = $widget->update($widget_input, array());
    ob_start(); the_widget($w, $instance); $out = ob_get_clean();
    $ok = is_string($out) && stripos($out, 'Database error') === false && stripos($out, 'Fatal') === false;
    step("widget $w renders", $ok, $ok ? '' : substr(strip_tags($out), 0, 160));
    if ($w === 'WPFB_FileListWidget') step('file list widget lists the file', strpos($out, 'smoke.txt') !== false);
}

// 6. downloads
$dl = $base . '/?wpfb_dl=' . $file->GetId();
list($code, $hdr, $body) = http($dl);
step('download 200', $code === 200 && strlen($body) === filesize($file->GetLocalPath()), "code=$code len=" . strlen($body));
list($code, $hdr) = http($dl, array('Range: bytes=0-3'));
step('download 206', $code === 206 && preg_match('/Content-Range: bytes 0-3\//i', $hdr), "code=$code");
preg_match('/Last-Modified: ([^\r\n]+)/i', $hdr, $m);
list($code) = http($dl, array('If-Modified-Since: ' . ($m[1] ?? '')));
step('download 304', $code === 304, "code=$code");
// WPFB_File::GetFile() serves a per-request static cache, so read the counter straight from the table.
global $wpdb;
$hits = (int) $wpdb->get_var($wpdb->prepare("SELECT file_hits FROM {$wpdb->wpfilebase_files} WHERE file_id = %d", $file->GetId()));
step('hit counter incremented', $hits >= 1, "hits=$hits");

// 7. thumbnails (a png so a thumbnail gets generated)
$img = WPFB_Admin::InsertFile(array('file_category' => $cat_id, 'file_remote_uri' => $base . '/wp-includes/images/w-logo-blue.png', 'file_rename' => 'smoke.png'));
step('insert image', empty($img['error']) && !empty($img['file']), $img['error'] ?? '');
$imgf = $img['file'] ?? null;
if ($imgf) {
    step('image gets thumbnail', !empty($imgf->file_thumbnail));
    $url = $imgf->GetIconUrl();
    step('icon url is signed', strpos($url, '&sig=') !== false, $url);
    // SendFile() calls header_remove(), so the X-Fallback-Thumb marker never reaches the client;
    // tell the real thumbnail from the generic fallback icon by comparing bodies instead.
    $real = file_get_contents($imgf->GetThumbPath());
    list($code, $hdr, $body) = http(site_url_for($url));
    step('thumb signed 200', $code === 200 && $body === $real, "code=$code len=" . strlen($body));
    list($code, $hdr, $body) = http(site_url_for(preg_replace('/&sig=[0-9a-f]+/', '', $url)));
    step('thumb-public-legacy-url', $code === 200 && $body === $real, 'public file without sig still served');
    // thumbnail.php only denies when the item is inaccessible AND "hide inaccessible files" is on
    // (with it off, templates deliberately show locked files with their icons).
    $imgf->SetReadPermissions(array('administrator')); $imgf->DBSave();
    $opts = get_option(WPFB_OPT_NAME); $opts_backup = $opts; $opts['hide_inaccessible'] = 1; update_option(WPFB_OPT_NAME, $opts);
    list($code, $hdr, $body) = http(site_url_for(preg_replace('/&sig=[0-9a-f]+/', '', $url)));
    step('thumb private without sig falls back', $code === 200 && $body !== $real, "code=$code len=" . strlen($body));
    list($code, $hdr, $body) = http(site_url_for($url));
    step('thumb private with sig served', $code === 200 && $body === $real, "code=$code");
    update_option(WPFB_OPT_NAME, $opts_backup);
}

// 8. search
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
if (getenv('WPFB_KEEP_DATA') !== '1') {
    $file->Remove(); if ($imgf) $imgf->Remove(); if ($dropped) $dropped->Remove(); $cat['cat']->Delete();
}
exit($fails === 0 ? 0 : 1);
