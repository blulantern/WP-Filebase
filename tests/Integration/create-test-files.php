<?php

class TestFileSet {
    private $local_files;

    function __construct() {
        wpfb_loadclass('Download','Admin');
        $dir = WPFB_Core::UploadDir() . '/.tmp/';
        WPFB_Admin::Mkdir($dir);

        $test_files = array(
            'banner.png' => 'https://s.w.org/style/images/about/WordPress-logotype-standard.png',
            'small.txt' => 'https://wordpress.org/robots.txt'
        );



        $this->local_files = array();

        foreach($test_files as $f => $u) {
            $fn = $dir.$f;
            $this->local_files[$f] = $fn;
            if(file_exists($fn)) continue;
            echo "Downloading test file $u\n";
            WPFB_Download::SideloadFile($u, $fn);

            if(!file_exists($fn))
                throw Exception("Failed to download $u => $fn");
        }
    }

    function getSmallTxt() {
        return $this->local_files['small.txt'];
    }

    function getImageBanner() {
        return $this->local_files['banner.png'];
    }
}

