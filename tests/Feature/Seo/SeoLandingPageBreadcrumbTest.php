<?php

namespace Tests\Feature\Seo;

use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SeoLandingPageBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    public function test_birthday_gifts_for_husband_includes_relationship_parent_crumb(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        SeoLandingPage::factory()->published()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $canonical = DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband', absolute: true);
        $husbandUrl = DiscoveryUrl::relationship('husband');

        $html = $this->get(DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->getContent();

        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString(Terminology::giftIdeas(), $html);
        $this->assertStringContainsString(Terminology::gifts().' for Husband', $html);
        $this->assertStringContainsString('Birthday Gifts for Husband', $html);
        $this->assertStringContainsString('href="'.$husbandUrl.'"', $html);
        $this->assertStringContainsString('href="'.DiscoveryUrl::giftIdeas().'"', $html);
        $this->assertSame('/birthday-gifts-for-husband', DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'));
        $this->assertFalse(Route::has('discovery.budget.show'));
        $this->assertSame('/gifts-for/husband', $husbandUrl);
        $this->assertSame('/gifts/example-gift', DiscoveryUrl::gift('example-gift'));
    }

    public function test_birthday_gifts_for_coffee_lovers_includes_occasion_parent_crumb(): void
    {
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $coffee = Interest::query()->create([
            'name' => 'Coffee',
            'slug' => 'coffee',
            'is_active' => true,
        ]);

        $page = SeoLandingPage::factory()->published()->create([
            'name' => 'Birthday Gifts for Coffee Lovers',
            'slug' => 'birthday-gifts-for-coffee-lovers',
            'heading' => 'Birthday Gifts for Coffee Lovers',
            'occasion_id' => $birthday->id,
            'relationship_id' => null,
            'is_indexable' => true,
        ]);
        $page->interests()->sync([$coffee->id]);

        $html = $this->get(DiscoveryUrl::seoLandingPage('birthday-gifts-for-coffee-lovers'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(Terminology::giftIdeas(), $html);
        $this->assertStringContainsString('Birthday '.Terminology::gifts(), $html);
        $this->assertStringContainsString('Birthday Gifts for Coffee Lovers', $html);
        $this->assertStringContainsString('href="'.DiscoveryUrl::occasion('birthday').'"', $html);
        $this->assertStringNotContainsString('href="'.DiscoveryUrl::relationship('husband').'"', $html);
    }
}
