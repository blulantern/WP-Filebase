<?php
/**
 * Creates / removes the categories and files the drag & drop e2e tests move around.
 *   php tests/e2e/fixture.php setup            -> JSON with the ids
 *   php tests/e2e/fixture.php teardown '<json>' -> removes them
 * Env: WP_ROOT (WordPress install), WP_BASE_URL (site URL), as in mint-cookies.php.
 */
$root = getenv('WP_ROOT') ?: '/home/jfox/Projects/filebase/filebase';
$base = getenv('WP_BASE_URL') ?: 'http://localhost:8090';
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
ob_start(); // keep plugin notices out of the JSON
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
wp_set_current_user(1);
wpfb_loadclass('Core', 'Admin', 'Category', 'File');
ob_end_clean();

$cmd = $argv[1] ?? 'setup';
if ($cmd === 'setup') {
    $t = time();
    $a = WPFB_Admin::InsertCategory(array('cat_name' => "DnD A $t", 'cat_folder' => "dnd-a-$t"));
    $b = WPFB_Admin::InsertCategory(array('cat_name' => "DnD B $t", 'cat_folder' => "dnd-b-$t"));
    $ac = WPFB_Admin::InsertCategory(array('cat_name' => "DnD A child $t", 'cat_folder' => "dnd-a-child-$t", 'cat_parent' => $a['cat']->GetId()));
    $fa = WPFB_Admin::InsertFile(array('file_category' => $a['cat']->GetId(), 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-in-a-$t.txt"));
    $fr = WPFB_Admin::InsertFile(array('file_category' => 0, 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-root-$t.txt"));
    $fo = WPFB_Admin::InsertFile(array('file_category' => $a['cat']->GetId(), 'file_remote_uri' => $base . '/license.txt', 'file_rename' => "dnd-offline-$t.txt"));
    foreach (array($a, $b, $ac, $fa, $fr, $fo) as $r) {
        if (!empty($r['error'])) { fwrite(STDERR, $r['error'] . "\n"); exit(1); }
    }
    @unlink($fo['file']->GetLocalPath()); // "offline": row exists, file does not
    echo json_encode(array(
        'cat_a' => $a['cat']->GetId(), 'cat_b' => $b['cat']->GetId(), 'cat_a_child' => $ac['cat']->GetId(),
        'file_in_a' => $fa['file']->GetId(), 'file_root' => $fr['file']->GetId(), 'file_offline' => $fo['file']->GetId(),
        'cat_a_path' => $a['cat']->GetLocalPathRel(), 'cat_b_path' => $b['cat']->GetLocalPathRel(),
    ));
    exit(0);
}
if ($cmd === 'teardown') {
    $ids = json_decode($argv[2] ?? '{}', true) ?: array();
    foreach (array('file_in_a', 'file_root', 'file_offline') as $k) {
        if (!empty($ids[$k]) && ($f = WPFB_File::GetFile($ids[$k]))) $f->Remove();
    }
    foreach (array('cat_a_child', 'cat_b', 'cat_a') as $k) { // deepest first
        if (!empty($ids[$k]) && ($c = WPFB_Category::GetCat($ids[$k]))) {
            foreach ($c->GetChildFiles() as $f) $f->Remove(); // e.g. an upload a failed test left behind
            $c->Delete();
        }
    }
    exit(0);
}
fwrite(STDERR, "usage: fixture.php setup|teardown <json>\n");
exit(2);
