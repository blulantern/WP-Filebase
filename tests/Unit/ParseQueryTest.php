<?php
use PHPUnit\Framework\TestCase;

// WPFB_Core::ParseQuery() runs on every WP_Query's `parse_query` action. These stubs record the
// filters it installs; the unit suite has no WordPress.
if (!function_exists('add_filter')) {
    function add_filter($tag, $cb, $priority = 10, $accepted_args = 1) { $GLOBALS['wpfb_test_filters'][] = $tag; return true; }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return false; }
}

final class ParseQueryTest extends TestCase
{
    protected function setUp(): void
    {
        wpfb_loadclass('Core', 'Search');
        WPFB_Core::$settings = (object) array('search_integration' => false, 'file_browser_post_id' => -1);
        $GLOBALS['wp_query'] = $this->query(array());
        $GLOBALS['wpfb_test_filters'] = array();
        $_GET = array();
    }

    /** A stand-in for WP_Query after parse_query(): only the members ParseQuery() reads. */
    private function query(array $vars): stdClass
    {
        $q = new stdClass();
        $q->query = $vars;
        $q->query_vars = $vars;
        $q->tax_query = null;
        $q->post = null;
        $q->queried_object_id = 0;
        return $q;
    }

    public function test_query_without_post_type_runs_without_warning_and_installs_nothing(): void
    {
        // The main query of the home page, a page, a search: WP_Query::fill_query_vars() never
        // adds 'post_type', so the key is absent. PHPUnit turns the "Undefined array key" warning
        // into a failure.
        $q = $this->query(array('pagename' => 'home'));
        WPFB_Core::ParseQuery($q);
        $this->assertSame(array(), $GLOBALS['wpfb_test_filters']);
    }

    public function test_filepage_post_type_installs_permission_hooks(): void
    {
        $q = $this->query(array('post_type' => 'wpfb_filepage'));
        WPFB_Core::ParseQuery($q);
        $this->assertSame(array('posts_join', 'posts_search'), $GLOBALS['wpfb_test_filters']);
    }

    public function test_post_type_list_containing_filepage_installs_permission_hooks(): void
    {
        // REST and block queries pass post_type as an array.
        $q = $this->query(array('post_type' => array('post', 'wpfb_filepage')));
        WPFB_Core::ParseQuery($q);
        $this->assertSame(array('posts_join', 'posts_search'), $GLOBALS['wpfb_test_filters']);
    }

    public function test_other_post_types_install_nothing(): void
    {
        $q = $this->query(array('post_type' => array('post', 'page')));
        WPFB_Core::ParseQuery($q);
        $this->assertSame(array(), $GLOBALS['wpfb_test_filters']);
    }

    public function test_file_category_tax_query_installs_permission_hooks(): void
    {
        $q = $this->query(array());
        $q->tax_query = (object) array('queried_terms' => array('wpfb_file_category' => array('terms' => array('po'))));
        WPFB_Core::ParseQuery($q);
        $this->assertSame(array('posts_join', 'posts_search'), $GLOBALS['wpfb_test_filters']);
    }
}
