<?php
use PHPUnit\Framework\TestCase;

final class SearchSqlTest extends TestCase
{
    private $escLike;
    private $prepare;

    protected function setUp(): void
    {
        wpfb_loadclass('Search');
        // Mirror wpdb::esc_like and a minimal wpdb::prepare for %s.
        $this->escLike = fn($s) => addcslashes($s, '_%\\');
        $this->prepare = function ($sql, ...$args) {
            foreach ($args as $a) {
                // explode/implode rather than preg_replace: a regex replacement would eat the
                // doubled backslashes that real wpdb::prepare (esc_sql + vsprintf) keeps.
                $parts = explode('%s', $sql, 2);
                $sql = $parts[0] . "'" . addslashes($a) . "'" . ($parts[1] ?? '');
            }
            return $sql;
        };
    }

    private function build(array $terms, string $p = '%', ?string $id3 = null): string
    {
        return WPFB_Search::BuildTermsWhere($terms, array('t.file_name', 't.file_tags'), $p, $id3, $this->escLike, $this->prepare);
    }

    public function test_plain_term_builds_like_on_each_column(): void
    {
        $sql = $this->build(array('report'));
        $this->assertSame("(1 AND ( (t.file_name LIKE '%report%') OR (t.file_tags LIKE '%report%')) )", $sql);
    }

    public function test_quote_percent_and_underscore_are_escaped(): void
    {
        $sql = $this->build(array("o'neil_50%"));
        // esc_like adds one backslash before _ and %; prepare then escapes ' and doubles backslashes.
        $this->assertStringContainsString("LIKE '%o\\'neil\\\\_50\\\\%%'", $sql);
    }

    public function test_wildcard_star_becomes_percent_only_for_star(): void
    {
        $sql = $this->build(array('rep*rt'));
        $this->assertStringContainsString("LIKE 'rep%rt'", $sql);
    }

    public function test_leading_dash_negates(): void
    {
        $sql = $this->build(array('-draft'));
        $this->assertStringContainsString('AND NOT (', $sql);
        $this->assertStringContainsString("LIKE '%draft%'", $sql);
    }

    public function test_empty_terms_are_dropped(): void
    {
        $sql = $this->build(array('', '-', 'ok'));
        $this->assertSame(1, substr_count($sql, ' AND ('), 'only the real term produces a clause');
        $this->assertStringNotContainsString("LIKE '%%'", $sql);
    }

    public function test_id3_column_added_for_positive_terms_only(): void
    {
        $sql = $this->build(array('x', '-y'), '%', 'i.keywords');
        $this->assertSame(1, substr_count($sql, "i.keywords LIKE"));
    }
}
