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
