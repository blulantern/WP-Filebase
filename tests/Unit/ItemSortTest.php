<?php
use PHPUnit\Framework\TestCase;

final class ItemSortTest extends TestCase
{
    protected function setUp(): void
    {
        wpfb_loadclass('Item');
    }

    private function items(array $names): array
    {
        $out = array();
        foreach ($names as $n) {
            $i = new WPFB_Item();
            $i->file_name = $n;
            $out[] = $i;
        }
        return $out;
    }

    public function test_sort_ascending_by_sql_clause(): void
    {
        $items = $this->items(array('b', 'c', 'a'));
        WPFB_Item::Sort($items, 'file_name ASC');
        $this->assertSame(array('a', 'b', 'c'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_sort_descending_by_arrow_prefix(): void
    {
        $items = $this->items(array('b', 'c', 'a'));
        WPFB_Item::Sort($items, '>file_name');
        $this->assertSame(array('c', 'b', 'a'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_sort_strips_hostile_property_names(): void
    {
        $items = $this->items(array('b', 'a'));
        WPFB_Item::Sort($items, "file_name'); system('id'); //");
        $this->assertSame(array('a', 'b'), array_map(fn($i) => $i->file_name, $items));
    }

    public function test_missing_property_sorts_as_empty(): void
    {
        $items = $this->items(array('b', 'a'));
        $items[] = new WPFB_Item(); // no file_name at all
        WPFB_Item::Sort($items, 'file_name ASC');
        $this->assertSame(array('', 'a', 'b'), array_map(fn($i) => (string) ($i->file_name ?? ''), $items));
    }
}
