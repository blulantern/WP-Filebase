<?php
/**
 * Prints WordPress auth cookies for user 1 as a JSON array in Playwright's addCookies() shape.
 * Used by auth.setup.js when WP_PASS is not set, so the e2e suite can run against the local
 * dev site without a password. Env: WP_ROOT (WordPress install), WP_BASE_URL (site URL).
 */
$root = getenv('WP_ROOT') ?: '/home/jfox/Projects/filebase/filebase';
$base = getenv('WP_BASE_URL') ?: 'http://localhost:8090';
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
ob_start(); // keep plugin notices out of the JSON
require $root . '/wp-load.php';
ob_end_clean();
$uid = 1;
$exp = time() + DAY_IN_SECONDS;
$cookies = array();
foreach (array('auth' => AUTH_COOKIE, 'logged_in' => LOGGED_IN_COOKIE) as $scheme => $name) {
    $cookies[] = array('name' => $name, 'value' => wp_generate_auth_cookie($uid, $exp, $scheme),
        'domain' => parse_url($base, PHP_URL_HOST), 'path' => '/', 'expires' => $exp, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax');
}
echo json_encode($cookies);
