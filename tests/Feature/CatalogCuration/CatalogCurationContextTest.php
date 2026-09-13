<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\CalculateCatalogCurationContextAction;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCurationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_counts_each_product_once_and_current_run_semantics_supersede_history(): void
    {
        [$target, $peer, $incompatible] = Product::factory()->count(3)->create();
        $previous = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Completed]);
        $current = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Running]);

        $this->audit($previous, $target, ProductCurationAuditOutcome::Completed, 'old concept', taxonomyId: 1);
        $this->audit($previous, $peer, ProductCurationAuditOutcome::Completed, 'tea infuser', taxonomyId: 2);
        $this->audit($previous, $incompatible, ProductCurationAuditOutcome::Completed, 'tea infuser', 'old', 1);
        $audit = $this->audit($current, $target, ProductCurationAuditOutcome::SemanticReady, 'tea infuser', taxonomyId: 1);
        $this->audit($current, $peer, ProductCurationAuditOutcome::SemanticReady, 'tea infuser', taxonomyId: 2);

        $context = app(CalculateCatalogCurationContextAction::class)->execute($audit);

        $this->assertSame([$peer->id], $context['peer_product_ids']['concept']);
        $this->assertSame(1, $context['peer_counts']['concept']);
        $this->assertSame(28, $context['factors']['saturation_novelty']);
        $this->assertSame(2, $context['snapshot']['corpus_product_count']);
        $this->assertSame(array_sum($context['factors']), $context['score']);
        $this->assertTrue($context['snapshot']['signals']['possible_concept_duplicate']);
        $this->assertFalse($context['snapshot']['signals']['concept_oversaturated']);
        $this->assertSame(['relationships:1'], $context['snapshot']['uncovered_strong_taxonomy_ids']);
        $this->assertSame(15, $context['factors']['taxonomy_gap']);
        $this->assertSame(13, $context['factors']['differentiation']);
        $this->assertSame(8, $context['factors']['intents']);
        $this->assertSame(0, $context['factors']['niche']);
        $this->assertSame(64, strlen($context['fingerprint']));
    }

    public function test_intent_and_niche_contributions_are_graduated_by_semantics_and_peer_context(): void
    {
        [$target, $firstPeer, $secondPeer] = Product::factory()->count(3)->create();
        $run = ProductCurationAuditRun::query()->create(['status' => ProductCurationRunStatus::Running]);
        $audit = $this->audit($run, $target, ProductCurationAuditOutcome::SemanticReady, 'gardening-kit', niche: 'strong');
        $this->audit($run, $firstPeer, ProductCurationAuditOutcome::SemanticReady, 'gardening-kit');
        $this->audit($run, $secondPeer, ProductCurationAuditOutcome::SemanticReady, 'different-concept');

        $context = app(CalculateCatalogCurationContextAction::class)->execute($audit);

        $this->assertSame(6, $context['factors']['intents']);
        $this->assertSame(4, $context['factors']['niche']);
        $this->assertSame(13, $context['factors']['differentiation']);
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
