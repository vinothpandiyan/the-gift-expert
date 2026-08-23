<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\RankProductsForDiscoveryContextAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDiscoveryRankingCatalog;
use Tests\TestCase;

class RankProductsForDiscoveryContextActionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiscoveryRankingCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDiscoveryRankingCatalog();
    }

    public function test_husband_and_anniversary_context_prefers_romantic_lamp_over_generic_alarm_clock(): void
    {
        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$this->alarmClock, $this->romanticLamp]),
            new DiscoveryRankingContext('seo_landing', [
                'relationship_id' => $this->husband->id,
                'occasion_id' => $this->anniversary->id,
            ]),
        );

        $this->assertSame($this->romanticLamp->id, $ranked[0]->product->id);
        $this->assertGreaterThanOrEqual($ranked[1]->score, $ranked[0]->score);
        $this->assertArrayHasKey('occasion_match', $ranked[0]->scoreBreakdown);
    }

    public function test_colleague_and_birthday_context_can_rank_alarm_clock_highly(): void
    {
        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$this->wallet, $this->alarmClock]),
            new DiscoveryRankingContext('seo_landing', [
                'relationship_id' => $this->colleague->id,
                'occasion_id' => $this->birthday->id,
            ]),
        );

        $this->assertSame($this->alarmClock->id, $ranked[0]->product->id);
        $this->assertGreaterThan($ranked[1]->score, $ranked[0]->score);
    }

    public function test_breadth_penalty_is_capped_for_many_relationship_tags(): void
    {
        $extraRelationships = [];

        foreach (range(1, 5) as $index) {
            $extraRelationships[] = Relationship::query()->create([
                'name' => 'Extra '.$index,
                'slug' => 'extra-'.$index,
                'is_active' => true,
                'sort_order' => $index,
            ])->id;
        }

        $broadProduct = $this->catalogGift(
            'Ultra Broad Gift',
            'ultra-broad-gift',
            ['price_amount' => '299.00', 'price_currency' => 'INR'],
            array_merge(
                collect([$this->husband, $this->wife, $this->boyfriend, $this->colleague, $this->father, $this->brother, $this->friends])
                    ->pluck('id')
                    ->all(),
                $extraRelationships,
            ),
            [$this->birthday->id],
            $this->electronics,
        );

        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$broadProduct]),
            new DiscoveryRankingContext('relationship', ['relationship_id' => $this->husband->id]),
        );

        $this->assertSame(-5, $ranked[0]->scoreBreakdown['breadth_penalty']);
    }

    public function test_relationship_only_page_may_rank_narrower_product_above_broad_generic_product(): void
    {
        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$this->alarmClock, $this->romanticLamp]),
            new DiscoveryRankingContext('relationship', ['relationship_id' => $this->husband->id]),
        );

        $this->assertSame($this->romanticLamp->id, $ranked[0]->product->id);
    }

    public function test_featured_boost_is_modest(): void
    {
        $featuredClock = $this->catalogGift(
            'Featured Alarm Clock',
            'featured-alarm-clock',
            ['price_amount' => '299.00', 'price_currency' => 'INR', 'is_featured' => true],
            [$this->husband->id, $this->wife->id, $this->boyfriend->id, $this->colleague->id, $this->father->id, $this->brother->id, $this->friends->id],
            [$this->birthday->id],
            $this->electronics,
        );

        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$featuredClock, $this->romanticLamp]),
            new DiscoveryRankingContext('seo_landing', [
                'relationship_id' => $this->husband->id,
                'occasion_id' => $this->anniversary->id,
            ]),
        );

        $this->assertSame($this->romanticLamp->id, $ranked[0]->product->id);
        $this->assertSame(5, $ranked[1]->scoreBreakdown['featured'] ?? 0);
    }

    public function test_primary_category_match_scores_higher_than_secondary_category_match(): void
    {
        $secondaryOnly = $this->catalogGift(
            'Secondary Electronics Gift',
            'secondary-electronics-gift',
            ['price_amount' => '499.00', 'price_currency' => 'INR'],
            [$this->husband->id],
            [$this->birthday->id],
            $this->homeAndLiving,
            $this->electronics,
        );

        $primaryMatch = $this->catalogGift(
            'Primary Electronics Gift',
            'primary-electronics-gift',
            ['price_amount' => '499.00', 'price_currency' => 'INR'],
            [$this->husband->id],
            [$this->birthday->id],
            $this->electronics,
        );

        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$secondaryOnly, $primaryMatch]),
            new DiscoveryRankingContext('seo_landing', [
                'relationship_id' => $this->husband->id,
                'category_id' => $this->electronics->id,
            ]),
        );

        $this->assertSame($primaryMatch->id, $ranked[0]->product->id);
        $this->assertArrayHasKey('primary_category_match', $ranked[0]->scoreBreakdown);
        $this->assertArrayHasKey('secondary_category_match', $ranked[1]->scoreBreakdown);
    }

    public function test_repeated_ranking_is_deterministic(): void
    {
        $context = new DiscoveryRankingContext('seo_landing', [
            'relationship_id' => $this->colleague->id,
            'occasion_id' => $this->birthday->id,
        ]);
        $products = collect([$this->alarmClock, $this->laptopSleeve, $this->coffeeMug]);
        $action = app(RankProductsForDiscoveryContextAction::class);

        $first = collect($action->execute($products, $context))->pluck('product.id')->all();
        $second = collect($action->execute($products, $context))->pluck('product.id')->all();

        $this->assertSame($first, $second);
    }

    public function test_seo_landing_surface_does_not_apply_breadth_penalty(): void
    {
        $ranked = app(RankProductsForDiscoveryContextAction::class)->execute(
            collect([$this->alarmClock]),
            new DiscoveryRankingContext('seo_landing', [
                'relationship_id' => $this->husband->id,
                'occasion_id' => $this->birthday->id,
            ]),
        );

        $this->assertArrayNotHasKey('breadth_penalty', $ranked[0]->scoreBreakdown);
    }
}
