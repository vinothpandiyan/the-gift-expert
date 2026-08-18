<?php

namespace Tests\Unit\Support;

use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageMetaSeoLandingPageBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_type_and_occasion_parent_is_the_gift_type_taxonomy_page(): void
    {
        $returnGifts = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $page = SeoLandingPage::factory()->create([
            'name' => 'Birthday Return Gifts',
            'slug' => 'birthday-return-gifts',
            'heading' => 'Birthday Return Gifts',
            'gift_type_id' => $returnGifts->id,
            'occasion_id' => $birthday->id,
        ]);
        $page->load(['giftType', 'occasion', 'interests']);

        $crumbs = PageMeta::seoLandingPageBreadcrumbs($page);

        $this->assertSame([
            ['label' => 'Home', 'url' => url('/')],
            ['label' => 'Gift Ideas', 'url' => DiscoveryUrl::giftIdeas()],
            ['label' => 'Return Gifts', 'url' => DiscoveryUrl::giftType('return-gifts')],
            ['label' => 'Birthday Return Gifts', 'url' => null],
        ], $crumbs);
    }

    public function test_single_dimension_pages_do_not_add_a_taxonomy_parent(): void
    {
        $returnGifts = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'is_active' => true,
        ]);

        $page = SeoLandingPage::factory()->create([
            'heading' => 'Return Gifts',
            'gift_type_id' => $returnGifts->id,
        ]);
        $page->load(['giftType', 'interests']);

        $labels = array_column(PageMeta::seoLandingPageBreadcrumbs($page), 'label');

        $this->assertSame(['Home', 'Gift Ideas', 'Return Gifts'], $labels);
    }
}
