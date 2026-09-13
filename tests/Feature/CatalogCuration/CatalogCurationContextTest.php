<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\CalculateCatalogCurationContextAction;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCurationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_counts_each_product_once_and_current_run_semantics_supersede_history(): void
    {
        [$firstRelationship, $secondRelationship] = $this->seedRelationships(2);
        [$target, $peer, $incompatible] = Product::factory()->count(3)->create();
        $previous = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Completed]);
        $current = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Running]);

        $this->audit($previous, $target, ProductCurationAuditOutcome::Completed, 'old concept', taxonomyId: $firstRelationship);
        $this->audit($previous, $peer, ProductCurationAuditOutcome::Completed, 'tea infuser', taxonomyId: $secondRelationship);
        $this->audit($previous, $incompatible, ProductCurationAuditOutcome::Completed, 'tea infuser', 'old', $firstRelationship);
        $audit = $this->audit($current, $target, ProductCurationAuditOutcome::SemanticReady, 'tea infuser', taxonomyId: $firstRelationship);
        $this->audit($current, $peer, ProductCurationAuditOutcome::SemanticReady, 'tea infuser', taxonomyId: $secondRelationship);

        $context = app(CalculateCatalogCurationContextAction::class)->execute($audit);

        $this->assertSame([$peer->id], $context['peer_product_ids']['concept']);
        $this->assertSame(1, $context['peer_counts']['concept']);
        $this->assertSame(2, $context['snapshot']['corpus_product_count']);
        $this->assertTrue($context['snapshot']['target_excluded_from_all_coverage_counts']);
        $this->assertSame(array_sum($context['factors']), $context['score']);
        $this->assertTrue($context['snapshot']['signals']['possible_concept_duplicate']);
        $this->assertFalse($context['snapshot']['signals']['concept_oversaturated']);
        $this->assertSame(['relationships:'.$firstRelationship], $context['snapshot']['strong_taxonomy_ids']);
        $this->assertSame(0, $context['snapshot']['relative_coverage']['taxonomy']['details']['relationships'][0]['existing_strong_count']);
        $this->assertContains($context['factors']['differentiation'], range(10, 14));
        $this->assertSame(64, strlen($context['fingerprint']));
    }

    public function test_intent_and_niche_contributions_are_graduated_by_semantics_and_peer_context(): void
    {
        $this->seedRelationships(1);
        [$target, $firstPeer, $secondPeer] = Product::factory()->count(3)->create();
        $run = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Running]);
        $audit = $this->audit($run, $target, ProductCurationAuditOutcome::SemanticReady, 'gardening-kit', niche: 'strong');
        $this->audit($run, $firstPeer, ProductCurationAuditOutcome::SemanticReady, 'gardening-kit');
        $this->audit($run, $secondPeer, ProductCurationAuditOutcome::SemanticReady, 'different-concept');

        $context = app(CalculateCatalogCurationContextAction::class)->execute($audit);

        $this->assertGreaterThan(0, $context['factors']['intents']);
        $this->assertContains($context['factors']['niche'], range(4, 5));
        $this->assertContains($context['factors']['differentiation'], range(10, 14));
    }

    /**
     * @return list<int>
     */
    private function seedRelationships(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index): int => Relationship::query()->create([
                'name' => 'Relationship '.$index,
                'slug' => 'relationship-'.$index,
                'is_active' => true,
            ])->id)
            ->all();
    }

    private function audit(
        ProductCurationAuditRun $run,
        Product $product,
        ProductCurationAuditOutcome $outcome,
        string $concept,
        string $semanticVersion = '2',
        int $taxonomyId = 1,
        string $niche = 'none',
    ): ProductCurationAudit {
        return ProductCurationAudit::query()->create([
            'run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => $outcome,
            'semantic_evaluation' => [
                'gift_components' => ['uniqueness' => 5],
                'gift_intents' => ['practical'],
                'current_taxonomy_evaluations' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'taxonomy_suggestions' => [
                    'relationships' => [[
                        'id' => $taxonomyId,
                        'name' => 'Relationship '.$taxonomyId,
                        'slug' => 'relationship-'.$taxonomyId,
                        'strength' => 'strong',
                    ]],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'concept_key' => str($concept)->slug()->toString(),
                'differentiation_signals' => ['Clear product role'],
                'differentiation_strength' => 'medium',
                'niche_signals' => $niche === 'none' ? [] : ['An underserved scenario'],
                'niche_contribution' => $niche,
            ],
            'concept_key' => str($concept)->slug()->toString(),
            'concept_label' => str($concept)->title()->toString(),
            'evidence_snapshot' => [
                'product_id' => $product->id,
                'price' => ['amount' => '1200.00', 'currency' => 'INR', 'compare_at_amount' => null],
            ],
            'evidence_fingerprint' => str_repeat((string) ($product->id % 10), 64),
            'semantic_fingerprint' => hash('sha256', $product->id.'|'.$semanticVersion),
            'semantic_evaluator_version' => $semanticVersion,
            'prompt_version' => '2',
            'scoring_version' => '2',
            'context_version' => '2',
            'completed_at' => $outcome === ProductCurationAuditOutcome::Completed ? now() : null,
        ]);
    }
}
