<?php

namespace Tests\Unit\DiscoveryListing;

use App\DiscoveryListing\DiscoveryFilterOption;
use App\DiscoveryListing\DiscoveryFilterOptionTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscoveryFilterOptionTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_nests_children_under_visible_parents_without_queries(): void
    {
        $fashion = new DiscoveryFilterOption(1, 'Fashion & Accessories', 'fashion-and-accessories', 18, false);
        $jewellery = new DiscoveryFilterOption(2, 'Jewellery', 'fashion-and-accessories/jewellery', 7, false, 1);
        $home = new DiscoveryFilterOption(3, 'Home & Living', 'home-and-living', 22, false);
        $kitchen = new DiscoveryFilterOption(4, 'Kitchen & Dining', 'home-and-living/kitchen-and-dining', 9, false, 3);
        $electronics = new DiscoveryFilterOption(5, 'Electronics', 'electronics', 14, false);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $tree = DiscoveryFilterOptionTree::nest(collect([
            $fashion,
            $jewellery,
            $home,
            $kitchen,
            $electronics,
        ]));

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame('Fashion & Accessories', $tree[0]['option']->label);
        $this->assertSame(['Jewellery'], array_map(fn (DiscoveryFilterOption $option) => $option->label, $tree[0]['children']));
        $this->assertSame(7, $tree[0]['children'][0]->count);
        $this->assertSame('Home & Living', $tree[1]['option']->label);
        $this->assertSame(['Kitchen & Dining'], array_map(fn (DiscoveryFilterOption $option) => $option->label, $tree[1]['children']));
        $this->assertSame('Electronics', $tree[2]['option']->label);
        $this->assertSame([], $tree[2]['children']);
    }

    public function test_orphaned_children_render_as_roots(): void
    {
        $jewellery = new DiscoveryFilterOption(2, 'Jewellery', 'fashion-and-accessories/jewellery', 7, false, 1);

        $tree = DiscoveryFilterOptionTree::nest(collect([$jewellery]));

        $this->assertSame('Jewellery', $tree[0]['option']->label);
        $this->assertSame([], $tree[0]['children']);
    }
}
