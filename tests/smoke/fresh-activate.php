<?php
/**
 * Fresh-install activation, the way a real site does it: phase 1 deactivates the plugin and
 * deletes every option; phase 2 is a NEW PHP process (so the plugin boots with no settings)
 * that activates it. Invoked by run.php; exit code is phase 2's.
 */
$root = getenv('WP_ROOT') ?: '/home/jfox/Projects/filebase/filebase';
$base = getenv('BASE') ?: 'http://127.0.0.1:8090';
$phase = $argv[1] ?? 'all';
if ($phase === 'all') {
    foreach (array('clean', 'activate') as $p) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $p, $code);
        if ($code !== 0) exit($code);
    }
    exit(0);
}
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . ':' . (parse_url($base, PHP_URL_PORT) ?: 80);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ($phase === 'clean') {
    deactivate_plugins('wp-filebase/wp-filebase.php');
    foreach (array('wpfilebase', 'wpfilebase_ftags', 'wpfilebase_tpls_file', 'wpfilebase_tpls_cat', 'wpfilebase_ptpls_file',
        'wpfilebase_ptpls_cat', 'wpfilebase_list_tpls', 'wpfilebase_cron_sync_stats', 'wpfb_css', 'wpfb_install_time') as $o) {
        delete_option($o);
    }
    exit(0);
}
$r = activate_plugin('wp-filebase/wp-filebase.php');
if (is_wp_error($r)) { fwrite(STDERR, $r->get_error_message() . "\n"); exit(1); }
exit(0);
