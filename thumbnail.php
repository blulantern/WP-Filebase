<?php

if (!defined('WPFB')) {
    exit;
}

/* make sure we loaded the pluggable functions */
require( ABSPATH . WPINC . '/pluggable.php' );

define('WPFB_NO_CORE_INIT', true);
wpfb_loadclass('Core', 'File', 'Category', 'Download');

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
    header('X-Fallback-Thumb: 1');
    $img_path = ABSPATH . WPINC . '/images/';
    if (file_exists($img = $img_path . 'crystal/default.png')
        || file_exists($img = $img_path . 'media/default.png')
    ) {
        WPFB_Download::SendFile($img, array('cache_max_age' => -1)); //was 3600 * 12
    } else {
        // single transparent pixel gif
        header('Content-Type: image/gif');
        header('Cache-Control: public');
        echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
    }
    exit;
}


// if no thumbnail, redirect
if (empty($item->file_thumbnail) && empty($item->cat_icon)) {
    header('Cache-Control: public');
    wp_redirect($item->GetIconUrl(), 301/*permanently*/);
    exit;
}

// send thumbnail
WPFB_Download::SendFile($item->GetThumbPath(), array('cache_max_age' => -1));
exit;