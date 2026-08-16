<?php

namespace Tests\Feature\Mvp;

use App\Actions\Product\PublishProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Livewire\GiftFinder;
use App\Models\AffiliateClick;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class MvpSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mvp_flow_publish_discover_find_and_outbound_click(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Smoke Merchant',
            'slug' => 'smoke-merchant',
            'affiliate_network' => 'example',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'Personalized',
            'slug' => 'personalized',
            'is_active' => true,
        ]);

        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Smoke Frame',
            'slug' => 'smoke-frame',
            'short_description' => 'A smoke-test gift',
            'status' => ProductStatus::Draft,
            'price_amount' => '799.00',
            'price_currency' => 'INR',
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/smoke-frame.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $affiliateLink = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://merchant.example/smoke-frame',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);
        $product->occasions()->attach($occasion->id);

        app(PublishProductAction::class)->execute($product->fresh());

        $product->refresh();
        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertNotNull($product->published_at);

        $giftCanonical = DiscoveryUrl::gift($product->slug, absolute: true);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Smoke Frame', false)
            ->assertSee(Terminology::gift(), false)
            ->assertSee('<title>Smoke Frame | '.config('app.name').'</title>', false)
            ->assertSee('<link rel="canonical" href="'.$giftCanonical.'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('href="'.DiscoveryUrl::affiliateOut($affiliateLink->uuid).'"', false)
            ->assertDontSee('href="'.$affiliateLink->url.'"', false);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path))
            ->assertOk()
            ->assertSee('Smoke Frame', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);

        $this->get(DiscoveryUrl::occasion($occasion->slug))
            ->assertOk()
            ->assertSee('Smoke Frame', false);

        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Find a Gift', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);

        $component = Livewire::test(GiftFinder::class)
            ->set('occasion_id', $occasion->id)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->first();
        $this->assertNotNull($session);
        $this->assertDatabaseCount('recommendation_results', 1);
        $this->assertTrue(
            RecommendationResult::query()
                ->where('recommendation_session_id', $session->id)
                ->where('product_id', $product->id)
                ->exists(),
        );

        $component->assertRedirect(DiscoveryUrl::finderResults($session->uuid));

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Smoke Frame', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('href="'.DiscoveryUrl::affiliateOut($affiliateLink->uuid).'"', false);

        $this->assertSame(0, AffiliateClick::query()->count());

        $this->get(DiscoveryUrl::affiliateOut($affiliateLink->uuid))
            ->assertRedirect($affiliateLink->url);

        $this->assertSame(1, AffiliateClick::query()->count());

        $click = AffiliateClick::query()->first();
        $this->assertNotNull($click);
        $this->assertSame($affiliateLink->id, $click->affiliate_link_id);
        $this->assertSame($product->id, $click->product_id);
        $this->assertNull($click->recommendation_session_id);
        $this->assertNull($click->recommendation_result_id);

        $this->get(DiscoveryUrl::gift('missing-smoke-gift'))->assertNotFound();

        $productRoutes = collect(Route::getRoutes())
            ->filter(function ($route): bool {
                $uri = '/'.$route->uri();

                return str_starts_with($uri, '/products') || str_contains($uri, '/products/');
            })
            ->values();

        $this->assertCount(0, $productRoutes);
        $this->assertTrue(Route::has('discovery.gift.show'));
        $this->assertTrue(Route::has('discovery.finder.show'));
        $this->assertTrue(Route::has('discovery.affiliate.out'));
        $this->assertCount(12, collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with((string) $route->getName(), 'discovery.'),
        ));
    }
}
