<?php

namespace Tests\Feature\Discovery;

use App\Actions\Discovery\BuildDiscoveryRankingContextAction;
use App\Actions\Discovery\QueryRankedDiscoveryProductsAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\SeedsDiscoveryRankingCatalog;
use Tests\TestCase;

class RankedDiscoveryProductsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiscoveryRankingCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDiscoveryRankingCatalog();
    }

    public function test_relationship_page_orders_specialized_product_before_broad_alarm_clock(): void
    {
        $response = $this->get(DiscoveryUrl::relationship('husband'));

        $response->assertOk();

        $positions = $this->productPositions($response->getContent(), [
            $this->romanticLamp->name,
            $this->alarmClock->name,
        ]);

        $this->assertLessThan($positions[$this->alarmClock->name], $positions[$this->romanticLamp->name]);
    }

    public function test_seo_landing_ranking_orders_contextual_product_before_broad_match_on_anniversary_page(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'anniversary-gifts-for-husband-ranked',
            'heading' => 'Anniversary Gifts for Husband',
            'relationship_id' => $this->husband->id,
            'occasion_id' => $this->anniversary->id,
        ]);

        $context = app(BuildDiscoveryRankingContextAction::class)->fromSeoLandingPage($page);
        $products = app(QueryRankedDiscoveryProductsAction::class)->execute($context, 1);

        $this->assertContains($this->romanticLamp->id, collect($products->items())->pluck('id')->all());
        $this->assertContains($this->alarmClock->id, collect($products->items())->pluck('id')->all());
        $this->assertSame($this->romanticLamp->id, $products->items()[0]->id);
    }

    public function test_ineligible_product_never_appears_on_relationship_page(): void
    {
        $ineligible = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Wife Only Bracelet',
            'slug' => 'wife-only-bracelet',
        ]);
        $ineligible->relationships()->sync([$this->wife->id]);

        $response = $this->get(DiscoveryUrl::relationship('husband'));

        $response->assertOk()
            ->assertDontSee('Wife Only Bracelet', false);
    }

    public function test_pagination_has_no_duplicate_products_across_pages(): void
    {
        config(['discovery_ranking.per_page' => 2]);

        for ($index = 1; $index <= 6; $index++) {
            $product = GiftCatalogTestHelpers::publishedGift([
                'name' => 'Husband Gift '.$index,
                'slug' => 'husband-gift-'.$index,
                'published_at' => now()->subMinutes($index),
            ]);
            $product->relationships()->sync([$this->husband->id]);
            $product->categories()->sync([$this->electronics->id => ['is_primary' => true]]);
        }

        $pageOne = $this->get(DiscoveryUrl::relationship('husband'))->assertOk();
        $pageTwo = $this->get(DiscoveryUrl::relationship('husband').'?page=2')->assertOk();

        $pageOneIds = $this->extractProductSlugs($pageOne->getContent());
        $pageTwoIds = $this->extractProductSlugs($pageTwo->getContent());

        $this->assertNotEmpty($pageOneIds);
        $this->assertNotEmpty($pageTwoIds);
        $this->assertSame([], array_values(array_intersect($pageOneIds, $pageTwoIds)));
    }

    public function test_repeated_ranked_queries_return_identical_order(): void
    {
        $context = new DiscoveryRankingContext('relationship', ['relationship_id' => $this->husband->id]);
        $action = app(QueryRankedDiscoveryProductsAction::class);

        $first = collect($action->execute($context, 1)->items())->pluck('id')->all();
        $second = collect($action->execute($context, 1)->items())->pluck('id')->all();

        $this->assertSame($first, $second);
    }

    public function test_discovery_route_count_remains_fourteen(): void
    {
        $this->assertSame(
            14,
            collect(Route::getRoutes())->filter(
                fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
            )->count(),
        );
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function productPositions(string $html, array $names): array
    {
        $positions = [];

        foreach ($names as $name) {
            $position = strpos($html, $name);
            $this->assertNotFalse($position, "Expected to find [{$name}] in response HTML.");
            $positions[$name] = $position;
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function extractProductSlugs(string $html): array
    {
        preg_match_all('#/gifts/([a-z0-9\-]+)#', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
