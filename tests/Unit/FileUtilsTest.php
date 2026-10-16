<?php
use PHPUnit\Framework\TestCase;

final class FileUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !is_file(ABSPATH . 'wp-admin/includes/file.php')) {
            $this->markTestSkipped('needs a WordPress checkout next to the plugin (ABSPATH)');
        }
        wpfb_loadclass('FileUtils');
    }

    public function test_image_boundary_from_path_emits_no_deprecation_on_php85(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'wpfb') . '.png';
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, 0xFFFFFF);
        imagesetpixel($im, 1, 1, 0x000000);
        imagepng($im, $png);
        // phpunit.xml.dist converts deprecations to exceptions, so a deprecated call fails this test.
        $box = WPFB_FileUtils::GetImageBoundary($png);
        unlink($png);
        $this->assertSame(array(1, 1, 1, 1), $box);
    }
}
