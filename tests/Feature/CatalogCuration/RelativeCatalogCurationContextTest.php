<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\CalculateRelativeCatalogCurationContextAction;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelativeCatalogCurationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_taxonomy_scarcity_is_tie_safe_dimension_relative_and_excludes_target(): void
    {
        $ids = $this->seedTaxonomies(5);
        $run = $this->createRun();
        $rare = $this->audit($run, 'rare-concept', ['relationships' => [$ids['relationships'][0]]]);
        $common = $this->audit($run, 'common-concept', ['relationships' => [$ids['relationships'][4]]]);

        foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4] as $relationshipIndex => $count) {
            for ($index = 0; $index < $count; $index++) {
                $this->audit($run, "peer-{$relationshipIndex}-{$index}", ['relationships' => [$ids['relationships'][$relationshipIndex]]]);
            }
        }

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $rareContext = $calculator->execute($rare);
        $commonContext = $calculator->execute($common);
        $rareDetail = $rareContext['snapshot']['relative_coverage']['taxonomy']['details']['relationships'][0];
        $commonDetail = $commonContext['snapshot']['relative_coverage']['taxonomy']['details']['relationships'][0];

        $this->assertTrue($rareContext['snapshot']['target_excluded_from_all_coverage_counts']);
        $this->assertSame(0, $rareDetail['existing_strong_count']);
        $this->assertSame(1.0, $rareDetail['relative_scarcity']);
        $this->assertSame(4, $commonDetail['existing_strong_count']);
        $this->assertLessThan(0.25, $commonDetail['relative_scarcity']);
        $this->assertGreaterThan($commonContext['factors']['taxonomy_gap'], $rareContext['factors']['taxonomy_gap']);
    }

    public function test_singleton_context_novelty_and_saturated_concept_penalty_are_independent(): void
    {
        $ids = $this->seedTaxonomies(5);
        $run = $this->createRun();
        $rareSingleton = $this->audit($run, 'rare-singleton', ['interests' => [$ids['interests'][0]]], ['premium']);
        $commonSingleton = $this->audit($run, 'common-singleton', ['interests' => [$ids['interests'][4]]], ['practical']);
        $wallets = [];

        for ($index = 0; $index < 5; $index++) {
            $wallets[] = $this->audit($run, 'wallet', ['interests' => [$ids['interests'][4]]], ['practical']);
        }

        for ($index = 0; $index < 6; $index++) {
            $this->audit($run, "common-peer-{$index}", ['interests' => [$ids['interests'][4]]], ['practical']);
        }

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $rare = $calculator->execute($rareSingleton);
        $common = $calculator->execute($commonSingleton);
        $wallet = $calculator->execute($wallets[0]);

        $this->assertGreaterThan($common['factors']['saturation_novelty'], $rare['factors']['saturation_novelty']);
        $this->assertGreaterThan($wallet['factors']['saturation_novelty'], $common['factors']['saturation_novelty']);
        $this->assertSame(4, $wallet['snapshot']['relative_coverage']['concept_peer_count']);
        $this->assertLessThanOrEqual(16, $wallet['factors']['saturation_novelty']);
    }

    public function test_budget_gap_is_context_aware_and_price_alone_is_capped(): void
    {
        $ids = $this->seedTaxonomies(5);
        $run = $this->createRun();
        $gardening = $this->audit($run, 'garden-tool', ['interests' => [$ids['interests'][0]]], ['practical'], price: 300);
        $technology = $this->audit($run, 'tech-tool', ['interests' => [$ids['interests'][4]]], ['practical'], price: 300);
        $expensiveWithoutContext = $this->audit($run, 'expensive-generic', price: 15000);

        for ($index = 0; $index < 8; $index++) {
            $this->audit($run, "tech-peer-{$index}", ['interests' => [$ids['interests'][4]]], ['practical'], price: 300);
        }

        foreach ([700, 1500, 3000, 7000] as $index => $price) {
            $this->audit($run, "band-peer-{$index}", price: $price);
        }

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $gardenContext = $calculator->execute($gardening);
        $technologyContext = $calculator->execute($technology);
        $expensiveContext = $calculator->execute($expensiveWithoutContext);

        $this->assertGreaterThan($technologyContext['factors']['budget_gap'], $gardenContext['factors']['budget_gap']);
        $this->assertLessThanOrEqual(4, $expensiveContext['factors']['budget_gap']);
        $this->assertSame('10000-plus', $expensiveContext['snapshot']['budget_coverage_band']);
    }

    public function test_per_intent_scarcity_is_bounded_to_top_two(): void
    {
        $this->seedTaxonomies();
        $run = $this->createRun();
        $rare = $this->audit($run, 'rare-intents', intents: ['premium', 'experience', 'practical']);
        $common = $this->audit($run, 'common-intent', intents: ['practical']);

        for ($index = 0; $index < 8; $index++) {
            $this->audit($run, "practical-{$index}", intents: ['practical']);
        }

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $rareContext = $calculator->execute($rare);
        $commonContext = $calculator->execute($common);

        $this->assertCount(2, $rareContext['snapshot']['relative_coverage']['intents']['details']);
        $this->assertGreaterThan($commonContext['factors']['intents'], $rareContext['factors']['intents']);
        $this->assertLessThanOrEqual(10, $rareContext['factors']['intents']);
    }

    public function test_semantic_differentiation_and_niche_are_only_refined_within_their_ranges(): void
    {
        $this->seedTaxonomies();
        $run = $this->createRun();
        $oneSignal = $this->audit(
            $run,
            'one-signal',
            differentiationSignals: ['Personalized'],
            differentiation: 'medium',
            niche: 'medium',
        );
        $fiveSignals = $this->audit(
            $run,
            'five-signals',
            differentiationSignals: ['Personalized', 'Premium', 'Portable', 'Presented', 'Recipient-specific'],
            differentiation: 'medium',
            niche: 'medium',
        );

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $one = $calculator->execute($oneSignal);
        $five = $calculator->execute($fiveSignals);

        $this->assertGreaterThan($one['factors']['differentiation'], $five['factors']['differentiation']);
        $this->assertContains($one['factors']['differentiation'], range(10, 14));
        $this->assertContains($five['factors']['differentiation'], range(10, 14));
        $this->assertContains($one['factors']['niche'], range(2, 4));
        $this->assertContains($five['factors']['niche'], range(2, 4));
    }

    public function test_unused_zero_coverage_values_do_not_make_light_coverage_look_saturated(): void
    {
        $ids = $this->seedTaxonomies(8);
        $run = $this->createRun();
        $light = $this->audit($run, 'light-coverage', ['interests' => [$ids['interests'][1]]]);

        foreach (range(1, 3) as $index) {
            $this->audit($run, "photo-{$index}", ['interests' => [$ids['interests'][1]]]);
        }

        foreach (range(1, 6) as $index) {
            $this->audit($run, "coffee-{$index}", ['interests' => [$ids['interests'][2]]]);
        }

        foreach (range(1, 10) as $index) {
            $this->audit($run, "tech-{$index}", ['interests' => [$ids['interests'][7]]]);
        }

        $context = app(CalculateRelativeCatalogCurationContextAction::class)->execute($light);
        $detail = $context['snapshot']['relative_coverage']['taxonomy']['details']['interests'][0];

        $this->assertSame(3, $detail['existing_strong_count']);
        $this->assertGreaterThan(0.45, $detail['relative_scarcity']);
        $this->assertGreaterThan(0, $context['factors']['taxonomy_gap']);
    }

    public function test_sparse_dimension_uses_inverse_count_fallback(): void
    {
        $ids = $this->seedTaxonomies(2);
        $run = $this->createRun();
        $rare = $this->audit($run, 'sparse-rare', ['gift_types' => [$ids['gift_types'][0]]]);
        $common = $this->audit($run, 'sparse-common', ['gift_types' => [$ids['gift_types'][1]]]);
        $this->audit($run, 'sparse-common-peer', ['gift_types' => [$ids['gift_types'][1]]]);

        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $rareDetail = $calculator->execute($rare)['snapshot']['relative_coverage']['taxonomy']['details']['gift_types'][0];
        $commonDetail = $calculator->execute($common)['snapshot']['relative_coverage']['taxonomy']['details']['gift_types'][0];

        $this->assertSame(0, $rareDetail['existing_strong_count']);
        $this->assertSame(1.0, $rareDetail['relative_scarcity']);
        $this->assertSame(1, $commonDetail['existing_strong_count']);
        $this->assertEqualsWithDelta(0.5, $commonDetail['relative_scarcity'], 0.001);
    }

    public function test_shared_sparse_context_is_capped_and_score_is_repeatable_without_gift_score_dependency(): void
    {
        $ids = $this->seedTaxonomies(5);
        $run = $this->createRun();
        $target = $this->audit(
            $run,
            'singular-experience',
            [
                'relationships' => array_slice($ids['relationships'], 0, 3),
                'occasions' => array_slice($ids['occasions'], 0, 3),
                'interests' => array_slice($ids['interests'], 0, 3),
                'gift_types' => array_slice($ids['gift_types'], 0, 3),
            ],
            ['premium', 'experience', 'sentimental'],
            15000,
            ['Personalized', 'Premium', 'Presented', 'Recipient-specific'],
            'strong',
            'strong',
        );

        for ($index = 0; $index < 8; $index++) {
            $this->audit(
                $run,
                "common-{$index}",
                [
                    'relationships' => [$ids['relationships'][4]],
                    'occasions' => [$ids['occasions'][4]],
                    'interests' => [$ids['interests'][4]],
                    'gift_types' => [$ids['gift_types'][4]],
                ],
                ['practical'],
                300,
            );
        }

        $focused = $this->audit($run, 'focused-experience', ['interests' => [$ids['interests'][0]]], ['premium'], 15000, ['Personalized'], 'strong', 'strong');
        $calculator = app(CalculateRelativeCatalogCurationContextAction::class);
        $first = $calculator->execute($target);
        $focusedContext = $calculator->execute($focused);
        $coverage = $first['snapshot']['relative_coverage'];
        $scarcityFactors = [
            $first['factors']['saturation_novelty'],
            $first['factors']['taxonomy_gap'],
            $first['factors']['intents'],
            $first['factors']['budget_gap'],
            $first['factors']['niche'],
        ];

        $this->assertGreaterThan($coverage['shared_scarcity_cap'], $coverage['shared_scarcity_points_raw']);
        $this->assertLessThan(1.0, $coverage['shared_scarcity_scale']);
        $this->assertLessThanOrEqual(90, $first['score']);
        $this->assertLessThan(35 + 15 + 10 + 15 + 5, array_sum($scarcityFactors));
        $this->assertLessThanOrEqual(18, $first['score'] - $focusedContext['score']);

        $target->update(['gift_score' => 1]);
        $second = app(CalculateRelativeCatalogCurationContextAction::class)->execute($target->fresh());

        $this->assertSame($first['score'], $second['score']);
        $this->assertSame($first['factors'], $second['factors']);
        $this->assertSame($first['peer_counts'], $second['peer_counts']);
    }

    private function createRun(): ProductCurationAuditRun
    {
        return ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Running]);
    }

    /**
     * @return array{relationships: list<int>, occasions: list<int>, interests: list<int>, gift_types: list<int>}
     */
    private function seedTaxonomies(int $count = 1): array
    {
        $ids = [
            'relationships' => [],
            'occasions' => [],
            'interests' => [],
            'gift_types' => [],
        ];

        foreach (range(1, $count) as $index) {
            $ids['relationships'][] = Relationship::query()->create(['name' => "Relationship {$index}", 'slug' => "relationship-{$index}", 'is_active' => true])->id;
            $ids['occasions'][] = Occasion::query()->create(['name' => "Occasion {$index}", 'slug' => "occasion-{$index}", 'is_active' => true])->id;
            $ids['interests'][] = Interest::query()->create(['name' => "Interest {$index}", 'slug' => "interest-{$index}", 'is_active' => true])->id;
            $ids['gift_types'][] = GiftType::query()->create(['name' => "Gift Type {$index}", 'slug' => "gift-type-{$index}", 'is_active' => true])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, list<int>>  $taxonomy
     * @param  list<string>  $intents
     * @param  list<string>  $differentiationSignals
     */
    private function audit(
        ProductCurationAuditRun $run,
        string $concept,
        array $taxonomy = [],
        array $intents = [],
        int $price = 1000,
        array $differentiationSignals = ['Useful distinction'],
        string $differentiation = 'medium',
        string $niche = 'none',
    ): ProductCurationAudit {
        $product = Product::factory()->create();
        $dimensions = ['relationships', 'occasions', 'interests', 'gift_types'];
        $evaluations = [];

        foreach ($dimensions as $dimension) {
            $evaluations[$dimension] = array_map(
                fn (int $id): array => [
                    'id' => $id,
                    'name' => $dimension.' '.$id,
                    'slug' => $dimension.'-'.$id,
                    'strength' => 'strong',
                ],
                $taxonomy[$dimension] ?? [],
            );
        }

        return ProductCurationAudit::query()->create([
            'run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => ProductCurationAuditOutcome::SemanticReady,
            'semantic_evaluation' => [
                'gift_intents' => $intents,
                'current_taxonomy_evaluations' => $evaluations,
                'taxonomy_suggestions' => array_fill_keys($dimensions, []),
                'concept_key' => $concept,
                'differentiation_signals' => $differentiationSignals,
                'differentiation_strength' => $differentiation,
                'niche_signals' => $niche === 'none' ? [] : ['Specific sparse context'],
                'niche_contribution' => $niche,
            ],
            'concept_key' => $concept,
            'concept_label' => str($concept)->headline()->toString(),
            'evidence_snapshot' => [
                'product_id' => $product->id,
                'price' => ['amount' => (string) $price, 'currency' => 'INR', 'compare_at_amount' => null],
            ],
            'evidence_fingerprint' => hash('sha256', 'evidence-'.$product->id),
            'semantic_fingerprint' => hash('sha256', 'semantic-'.$product->id),
            'semantic_evaluator_version' => '3',
            'prompt_version' => '3',
            'scoring_version' => '2',
            'context_version' => '2',
            'semantic_completed_at' => now(),
        ]);
    }
}
