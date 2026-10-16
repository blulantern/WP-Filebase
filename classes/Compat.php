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
     *
     * @param string $cl  Class name without the WPFB_ prefix.
     * @param string $fnc Static method name.
     * @return Closure
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

    /**
     * Like compileTemplate(), but never throws: a template that does not compile yields a
     * closure that renders an escaped inline message, so a bad template degrades to a notice
     * in the page instead of a fatal error.
     *
     * @param string $expr  Parsed template expression.
     * @param string $label What the template belongs to, shown in the message (e.g. 'file list widget').
     * @return Closure function ($f, $e = null): string
     */
    public static function compileTemplateOrError($expr, $label)
    {
        try {
            return self::compileTemplate($expr);
        } catch (WPFB_TemplateError $ex) {
            $msg = esc_html(sprintf('Template for %s is invalid: %s', $label, $ex->getMessage()));
            return static function ($f = null, $e = null) use ($msg) { return $msg; };
        }
    }

    /**
     * PHP 8 GD returns GdImage objects; PHP 7 returned resources.
     *
     * @param mixed $img
     * @return bool
     */
    public static function isGdImage($img)
    {
        return (class_exists('GdImage', false) && $img instanceof GdImage) || is_resource($img);
    }

    /**
     * Safe replacement for $s{0} / $s[0] on possibly null or empty strings.
     *
     * @param string|null $s
     * @return string
     */
    public static function firstChar($s)
    {
        $s = (string) $s;
        return $s === '' ? '' : $s[0];
    }

    /**
     * Send an HTTP status line via WordPress when loaded, else natively.
     *
     * @param int $code
     */
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
     *
     * @param int    $id
     * @param string $kind
     * @param string $name
     * @return string 16 hex characters
     */
    public static function thumbSig($id, $kind, $name)
    {
        return substr(wp_hash('wpfb-thumb|' . $kind . '|' . (int) $id . '|' . (string) $name), 0, 16);
    }
}
