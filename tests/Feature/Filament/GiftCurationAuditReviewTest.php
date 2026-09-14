<?php

namespace Tests\Feature\Filament;

use App\Enums\CatalogRole;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GiftCurationAuditReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_shows_a_useful_empty_curation_audit_state(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create();

        Livewire::test(EditGift::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Curation Audit')
            ->assertSee('No completed curation audit')
            ->assertSee('data-curation-audit-section', false)
            ->assertSee('data-curation-audit-empty', false);
    }

    public function test_edit_page_renders_the_latest_completed_audit_as_read_only_review_data(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create();
        $this->audit($product, [
            'gift_score' => 21,
            'concept_label' => 'Old Concept',
            'completed_at' => now()->subDay(),
        ]);
        $this->audit($product, [
            'gift_score' => 88,
            'catalog_value_score' => 72,
            'concept_key' => 'tea-ritual',
            'concept_label' => 'Tea Ritual',
            'catalog_role' => CatalogRole::UniquePick,
            'gift_intents' => ['sentimental', 'practical'],
            'why_this_gift' => 'A useful daily ritual for someone who takes time over loose-leaf tea.',
            'strengths' => ['Reusable', 'Distinct ritual'],
            'issues' => [[
                'code' => 'hard_taxonomy_conflict',
                'severity' => 'blocking',
                'message' => 'Current taxonomy violates an applicability rule.',
                'context' => ['forces_human_review' => true],
            ]],
            'gift_score_components' => [
                'uniqueness' => ['score' => 12, 'max' => 15, 'evidence_status' => 'known'],
            ],
            'saturation_novelty_factor' => 21,
            'peer_counts' => ['concept' => 6],
            'peer_product_ids' => ['concept' => [41, 42, 43, 44, 45, 46, 47, 48]],
            'semantic_evaluation' => [
                'current_taxonomy_evaluations' => [
                    'relationships' => [[
                        'name' => 'Father',
                        'strength' => 'weak',
                        'reason' => 'The fit is too broad.',
                    ]],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'taxonomy_suggestions' => [
                    'relationships' => [[
                        'name' => 'Mother',
                        'strength' => 'strong',
                    ]],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
            ],
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'action' => 'remove',
                'taxonomy' => ['name' => 'Father'],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'The assignment is materially misleading.',
            ], [
                'dimension' => 'relationships',
                'action' => 'retain',
                'taxonomy' => ['name' => 'Brother'],
                'severity' => 'advisory',
                'forces_human_review' => false,
                'reason' => 'The fit is valid but contextual.',
            ]],
        ]);

        Livewire::test(EditGift::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Gift Score')
            ->assertSee('88')
            ->assertSee('Tea Ritual')
            ->assertDontSee('Old Concept')
            ->assertSee('Hard Taxonomy Conflict')
            ->assertSee('Blocking')
            ->assertSee('Gift 41')
            ->assertSee('Gift 42')
            ->assertSee('View 2 more')
            ->assertDontSee('Gift IDs:')
            ->assertSee('Taxonomy findings')
            ->assertSee('Father')
            ->assertSee('Weak')
            ->assertSee('The fit is too broad.')
            ->assertSee('Mother')
            ->assertSee('Strong')
            ->assertSee('Removed')
            ->assertSee('Material')
            ->assertSee('Advisory')
            ->assertSee('Retained')
            ->assertSee('Brother')
            ->assertSee('Forces review')
            ->assertSee('Why this gift?')
            ->assertSee('Catalog context')
            ->assertSee('Audit metadata')
            ->assertSee('Concept novelty')
            ->assertDontSee('No completed curation audit');
    }

    public function test_curation_review_tab_uses_only_each_gifts_latest_completed_audit(): void
    {
        $this->actingAs(User::factory()->create());
        $needsReview = Product::factory()->create(['name' => 'Needs Curation Review']);
        $resolved = Product::factory()->create(['name' => 'Resolved Curation Review']);
        $pendingOnly = Product::factory()->create(['name' => 'Pending Audit Only']);

        $this->audit($needsReview, ['requires_human_review' => true]);
        $this->audit($resolved, [
            'requires_human_review' => true,
            'completed_at' => now()->subDay(),
        ]);
        $this->audit($resolved, ['requires_human_review' => false]);
        $this->audit($pendingOnly, [
            'outcome' => ProductCurationAuditOutcome::SemanticReady,
            'requires_human_review' => true,
            'completed_at' => null,
        ]);

        Livewire::test(ListGifts::class)
            ->assertSee('Curation Review')
            ->set('activeTab', 'curation_review')
            ->assertCanSeeTableRecords([$needsReview])
            ->assertCanNotSeeTableRecords([$resolved, $pendingOnly]);
    }

    public function test_list_exposes_curation_columns_and_filters_latest_completed_audits(): void
    {
        $this->actingAs(User::factory()->create());
        $lowGift = Product::factory()->create(['name' => 'Low Gift Score']);
        $lowCatalog = Product::factory()->create(['name' => 'Low Catalog Value']);
        $lowConfidence = Product::factory()->create(['name' => 'Low Confidence']);
        $candidate = Product::factory()->create(['name' => 'Replacement Candidate']);
        $healthy = Product::factory()->create(['name' => 'Healthy Gift']);

        $this->audit($lowGift, ['gift_score' => 64, 'requires_human_review' => true]);
        $this->audit($lowCatalog, ['catalog_value_score' => 49]);
        $this->audit($lowConfidence, ['ai_confidence' => CurationAiConfidence::Low]);
        $this->audit($candidate, [
            'recommendation' => CurationRecommendation::ReplaceCandidate,
            'issues' => [[
                'code' => 'concept_oversaturated',
                'severity' => 'warning',
                'message' => 'Oversaturated.',
                'context' => [],
            ]],
        ]);
        $this->audit($healthy);

        $list = Livewire::test(ListGifts::class)
            ->assertTableColumnExists('latestCompletedCurationAudit.gift_score')
            ->assertTableColumnExists('latestCompletedCurationAudit.catalog_value_score')
            ->assertTableColumnExists('latestCompletedCurationAudit.ai_confidence')
            ->assertTableColumnExists('latestCompletedCurationAudit.recommendation')
            ->assertTableColumnExists('latestCompletedCurationAudit.requires_human_review');

        $list->filterTable('curation_requires_human_review')
            ->assertCanSeeTableRecords([$lowGift])
            ->assertCanNotSeeTableRecords([$healthy]);

        Livewire::test(ListGifts::class)
            ->filterTable('curation_low_gift_score')
            ->assertCanSeeTableRecords([$lowGift])
            ->assertCanNotSeeTableRecords([$healthy]);

        Livewire::test(ListGifts::class)
            ->filterTable('curation_low_catalog_value')
            ->assertCanSeeTableRecords([$lowCatalog])
            ->assertCanNotSeeTableRecords([$healthy]);

        Livewire::test(ListGifts::class)
            ->filterTable('curation_low_confidence')
            ->assertCanSeeTableRecords([$lowConfidence])
            ->assertCanNotSeeTableRecords([$healthy]);

        Livewire::test(ListGifts::class)
            ->filterTable('curation_issue_code', 'concept_oversaturated')
            ->assertCanSeeTableRecords([$candidate])
            ->assertCanNotSeeTableRecords([$healthy]);

        Livewire::test(ListGifts::class)
            ->filterTable('curation_recommendation', CurationRecommendation::ReplaceCandidate->value)
            ->assertCanSeeTableRecords([$candidate])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function audit(Product $product, array $overrides = []): ProductCurationAudit
    {
        $run = ProductCurationAuditRun::query()->create([
            'status' => ProductCurationRunStatus::Completed,
        ]);

        return ProductCurationAudit::query()->create(array_merge([
            'run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => ProductCurationAuditOutcome::Completed,
            'gift_score' => 80,
            'catalog_value_score' => 70,
            'gift_score_components' => [],
            'gift_intents' => [],
            'strongest_fits' => [],
            'suggested_relationships' => [],
            'suggested_occasions' => [],
            'suggested_interests' => [],
            'suggested_gift_types' => [],
            'taxonomy_differences' => [],
            'concept_key' => 'healthy-gift',
            'concept_label' => 'Healthy Gift',
            'catalog_role' => CatalogRole::BestOverall,
            'why_this_gift' => 'A useful Gift for someone who values a thoughtful everyday experience.',
            'strengths' => [],
            'issues' => [],
            'ai_confidence' => CurationAiConfidence::High,
            'recommendation' => CurationRecommendation::Keep,
            'requires_human_review' => false,
            'semantic_evaluation' => [],
            'evidence_snapshot' => ['product_id' => $product->id],
            'evidence_fingerprint' => hash('sha256', 'evidence-'.$run->id),
            'semantic_fingerprint' => hash('sha256', 'semantic-'.$run->id),
            'semantic_evaluator_version' => '1',
            'prompt_version' => '1',
            'scoring_version' => '1',
            'context_version' => '1',
            'peer_product_ids' => [],
            'peer_counts' => [],
            'completed_at' => now(),
        ], $overrides));
    }
}
