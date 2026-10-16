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

    public function test_compile_template_or_error_returns_working_closure_for_valid_template(): void
    {
        $f = (object) array('name' => 'x');
        $fn = WPFB_Compat::compileTemplateOrError("'<i>'.\$f->name.'</i>'", 'file list widget');
        $this->assertSame('<i>x</i>', $fn($f));
    }

    public function test_compile_template_or_error_returns_inline_message_closure_for_broken_template(): void
    {
        $fn = WPFB_Compat::compileTemplateOrError("'unterminated", 'file list widget');
        $this->assertInstanceOf(Closure::class, $fn);
        $out = $fn(new stdClass());
        $this->assertStringContainsString('file list widget', $out);
        $this->assertStringContainsString('is invalid', $out);
        $this->assertStringNotContainsString('<', $out, 'message is HTML-escaped');
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
