<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\ApplyProductDiversityAction;
use App\Actions\Product\RankProductsForDiscoveryContextAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\DiscoveryRanking\RankedGift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDiscoveryRankingCatalog;
use Tests\TestCase;

class ApplyProductDiversityActionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiscoveryRankingCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDiscoveryRankingCatalog();
    }

    public function test_diversity_promotes_reasonably_close_alternative_categories(): void
    {
        config([
            'discovery_ranking.diversity.top_window' => 3,
            'discovery_ranking.diversity.max_same_primary_category_in_window' => 2,
            'discovery_ranking.diversity.max_consecutive_same_primary_category' => 1,
            'discovery_ranking.diversity.max_score_gap_for_diversity_swap' => 20,
        ]);

        $electronicsThree = $this->makeRankedElectronics('electronics-three', 90);
        $electronicsTwo = $this->makeRankedElectronics('electronics-two', 85);
        $homeGift = $this->makeRankedHome('home-gift', 80);

        $result = app(ApplyProductDiversityAction::class)->execute([
            $electronicsThree,
            $electronicsTwo,
            $homeGift,
        ]);

        $this->assertSame(
            [$electronicsThree->product->id, $homeGift->product->id, $electronicsTwo->product->id],
            collect($result)->pluck('product.id')->all(),
        );
    }

    public function test_diversity_does_not_promote_much_weaker_product_for_variety(): void
    {
        config([
            'discovery_ranking.diversity.top_window' => 2,
            'discovery_ranking.diversity.max_same_primary_category_in_window' => 1,
            'discovery_ranking.diversity.max_consecutive_same_primary_category' => 1,
            'discovery_ranking.diversity.max_score_gap_for_diversity_swap' => 10,
        ]);

        $strongElectronics = $this->makeRankedElectronics('strong-electronics', 90);
        $weakHome = $this->makeRankedHome('weak-home', 45);

        $result = app(ApplyProductDiversityAction::class)->execute([
            $strongElectronics,
            $weakHome,
        ]);

        $this->assertSame(
            [$strongElectronics->product->id, $weakHome->product->id],
            collect($result)->pluck('product.id')->all(),
        );
    }

    public function test_diversity_relaxes_when_no_reasonable_alternative_exists(): void
    {
        config([
            'discovery_ranking.diversity.top_window' => 3,
            'discovery_ranking.diversity.max_same_primary_category_in_window' => 1,
            'discovery_ranking.diversity.max_consecutive_same_primary_category' => 1,
            'discovery_ranking.diversity.max_score_gap_for_diversity_swap' => 5,
        ]);

        $first = $this->makeRankedElectronics('electronics-one', 90);
        $second = $this->makeRankedElectronics('electronics-two', 88);
        $third = $this->makeRankedElectronics('electronics-three', 86);

        $result = app(ApplyProductDiversityAction::class)->execute([$first, $second, $third]);

        $this->assertCount(3, $result);
        $this->assertSame(
            [$first->product->id, $second->product->id, $third->product->id],
            collect($result)->pluck('product.id')->all(),
        );
    }

    public function test_diversity_retains_full_product_set(): void
    {
        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$this->alarmClock, $this->romanticLamp, $this->wallet, $this->coffeeMug]),
            new DiscoveryRankingContext('relationship', ['relationship_id' => $this->husband->id]),
        );

        $result = app(ApplyProductDiversityAction::class)->execute($ranked);

        $this->assertCount(4, $result);
        $this->assertEqualsCanonicalizing(
            [$this->alarmClock->id, $this->romanticLamp->id, $this->wallet->id, $this->coffeeMug->id],
            collect($result)->pluck('product.id')->all(),
        );
    }

    private function makeRankedElectronics(string $slug, float $score): RankedGift
    {
        $product = $this->catalogGift(
            str($slug)->headline()->toString(),
            $slug,
            ['price_amount' => '299.00', 'price_currency' => 'INR'],
            [$this->husband->id],
            [$this->birthday->id],
            $this->electronics,
        );

        return new RankedGift($product, $score, []);
    }

    private function makeRankedHome(string $slug, float $score): RankedGift
    {
        $product = $this->catalogGift(
            str($slug)->headline()->toString(),
            $slug,
            ['price_amount' => '399.00', 'price_currency' => 'INR'],
            [$this->husband->id],
            [$this->birthday->id],
            $this->homeAndLiving,
        );

        return new RankedGift($product, $score, []);
    }
}
