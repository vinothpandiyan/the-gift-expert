<?php

namespace Tests\Support;

use App\CatalogCuration\HumanTaxonomyProposal;
use App\Enums\CatalogRole;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\Relationship;
use Illuminate\Support\Carbon;

trait BuildsHumanCurationFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function acceptedCurationRun(array $overrides = []): ProductCurationAuditRun
    {
        return ProductCurationAuditRun::query()->create(array_merge([
            'status' => ProductCurationRunStatus::Completed,
            'options' => [
                'product' => [],
                'status' => [],
                'only_missing' => false,
                'force' => false,
                'limit' => null,
            ],
            'products_total' => 0,
            'products_completed' => 0,
            'finished_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function curatedAudit(ProductCurationAuditRun $run, ?Product $product = null, array $overrides = []): ProductCurationAudit
    {
        $product ??= Product::factory()->create([
            'status' => ProductStatus::Draft,
        ]);

        $name = $product->name;

        return ProductCurationAudit::query()->create(array_merge([
            'run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => ProductCurationAuditOutcome::Completed,
            'gift_score' => 70,
            'catalog_value_score' => 55,
            'gift_score_components' => [
                'uniqueness' => ['score' => 10, 'max' => 15, 'evidence_status' => 'known'],
                'product_vendor_confidence' => ['score' => 8, 'max' => 10, 'evidence_status' => 'known'],
            ],
            'gift_intents' => ['practical'],
            'strongest_fits' => [],
            'suggested_relationships' => [],
            'suggested_occasions' => [],
            'suggested_interests' => [],
            'suggested_gift_types' => [],
            'taxonomy_differences' => [],
            'concept_key' => 'unique-concept-'.$product->id,
            'concept_label' => 'Unique Concept '.$product->id,
            'catalog_role' => CatalogRole::BestValue,
            'why_this_gift' => 'A useful Gift for someone who values a thoughtful everyday experience.',
            'strengths' => ['Useful daily ritual'],
            'issues' => [],
            'ai_confidence' => CurationAiConfidence::High,
            'recommendation' => CurationRecommendation::Review,
            'requires_human_review' => true,
            'semantic_evaluation' => [
                'why_this_gift' => 'A useful Gift for someone who values a thoughtful everyday experience.',
                'current_taxonomy_evaluations' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'taxonomy_suggestions' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
            ],
            'evidence_snapshot' => [
                'product_id' => $product->id,
                'name' => $name,
                'status' => $product->status->value,
                'price' => ['amount' => (string) ($product->price_amount ?? '1000'), 'currency' => 'INR', 'compare_at_amount' => null],
                'taxonomy' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'offers' => [],
                'images' => [],
                'provenance' => [],
            ],
            'evidence_fingerprint' => hash('sha256', 'evidence-'.$run->id.'-'.$product->id),
            'semantic_fingerprint' => hash('sha256', 'semantic-'.$run->id.'-'.$product->id),
            'catalog_context_snapshot' => [
                'price_band' => '1000-2499',
                'differentiation_strength' => 'medium',
                'relative_coverage' => [
                    'concept_peer_count' => 0,
                    'concept_exact_component' => 22,
                    'context_scarcity' => 0.4,
                    'shared_scarcity_scale' => 1,
                ],
            ],
            'semantic_evaluator_version' => '3',
            'prompt_version' => '3',
            'scoring_version' => '3',
            'context_version' => '3',
            'saturation_novelty_factor' => 28,
            'differentiation_factor' => 13,
            'budget_gap_factor' => 8,
            'taxonomy_gap_factor' => 4,
            'intents_factor' => 4,
            'niche_factor' => 2,
            'peer_product_ids' => ['concept' => []],
            'peer_counts' => ['concept' => 0],
            'completed_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * @return array{category: Category, relationship: Relationship}
     */
    protected function attachMerchandisingTaxonomy(Product $product): array
    {
        $category = Category::query()->create([
            'name' => 'Accessories',
            'slug' => 'accessories-'.uniqid(),
            'is_active' => true,
        ]);
        $relationship = Relationship::query()->create([
            'name' => 'Brother',
            'slug' => 'brother-'.uniqid(),
            'is_active' => true,
        ]);

        $product->categories()->sync([$category->id => ['is_primary' => true]]);
        $product->relationships()->sync([$relationship->id]);

        return [
            'category' => $category,
            'relationship' => $relationship,
        ];
    }

    /**
     * @param  list<int>  $remove
     * @param  list<int>  $add
     */
    protected function taxonomyProposal(
        array $remove = [],
        array $add = [],
        string $dimension = 'relationships',
        ?int $categoryFrom = null,
        ?int $categoryTo = null,
    ): HumanTaxonomyProposal {
        return HumanTaxonomyProposal::fromArray([
            'category' => [
                'from' => $categoryFrom,
                'to' => $categoryTo,
            ],
            $dimension => [
                'add' => $add,
                'remove' => $remove,
            ],
        ]);
    }
}
