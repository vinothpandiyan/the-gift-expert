<?php

namespace Tests\Unit\Actions\CatalogExpansion;

use App\Actions\CatalogCuration\CalculateRelativeCatalogCurationContextAction;
use App\Actions\CatalogExpansion\AssessIncomingProductGapContributionAction;
use App\Actions\CatalogExpansion\BuildIncomingCatalogExpansionReportAction;
use App\Actions\CatalogExpansion\CompareReplacementCandidateAction;
use App\Enums\IncomingCatalogContribution;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\ReplacementAdvice;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\Relationship;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class IncomingGapAndReplacementTest extends TestCase
{
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_classified_father_assignment_fills_critical_gap_without_mutating_existing_inventory(): void
    {
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $father = Relationship::query()->create([
            'name' => 'Father',
            'slug' => 'father',
            'is_active' => true,
        ]);
        $incoming['product']->relationships()->sync([$father->id]);
        $incoming['audit']->update([
            'concept_key' => 'desk-organizer',
            'catalog_value_score' => 72,
            'gift_score' => 74,
            'peer_product_ids' => ['concept' => []],
        ]);
        $audit = $incoming['audit']->fresh();
        $incoming = $incoming['product'];

        $contributions = app(AssessIncomingProductGapContributionAction::class)->execute(
            $incoming,
            $audit,
            ['critical' => [['name' => 'Father']], 'high' => [], 'medium' => []],
        );

        $this->assertContains(IncomingCatalogContribution::FillsCriticalGap, $contributions);
        $this->assertSame(ProductStatus::Draft, $incoming->fresh()->status);
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Archived)->count());
    }

    public function test_unrelated_wallet_does_not_fill_father_gap(): void
    {
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $incoming['product']->relationships()->sync([$husband->id]);
        $incoming['audit']->update([
            'concept_key' => 'wallet',
            'catalog_value_score' => 40,
            'gift_score' => 60,
            'peer_product_ids' => ['concept' => [10, 11, 12]],
        ]);
        $audit = $incoming['audit']->fresh();
        $incoming = $incoming['product'];

        $contributions = app(AssessIncomingProductGapContributionAction::class)->execute(
            $incoming,
            $audit,
            ['critical' => [['name' => 'Father']], 'high' => [], 'medium' => []],
        );

        $this->assertNotContains(IncomingCatalogContribution::FillsCriticalGap, $contributions);
        $this->assertContains(IncomingCatalogContribution::AddsNoMeaningfulCatalogValue, $contributions);
    }

    public function test_replacement_advice_does_not_archive_the_remove_candidate(): void
    {
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $existing = $this->retainedDraft(ProductCurationDecision::RemoveCandidate, run: $run);
        $incomingAudit = $incoming['audit']->fresh();
        $existingAudit = $existing['audit']->fresh();
        $incomingAudit->update([
            'concept_key' => 'wallet',
            'gift_score' => 78,
            'catalog_value_score' => 64,
        ]);
        $existingAudit->update([
            'concept_key' => 'wallet',
            'gift_score' => 52,
            'catalog_value_score' => 31,
        ]);

        $comparison = app(CompareReplacementCandidateAction::class)->execute(
            $incoming['product'],
            $incomingAudit->fresh(),
            $existing['product'],
            $existingAudit->fresh(),
        );

        $this->assertSame(ReplacementAdvice::ReplacementRecommended, $comparison['advice']);
        $this->assertSame(ProductStatus::Draft, $existing['product']->fresh()->status);
        $this->assertFalse($comparison['archived']);
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Archived)->count());
    }

    public function test_different_concepts_are_not_replacements(): void
    {
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $existing = $this->retainedDraft(ProductCurationDecision::RemoveCandidate, run: $run);
        $incoming['audit']->update(['concept_key' => 'perfume-gift-set', 'gift_score' => 80, 'catalog_value_score' => 70]);
        $existing['audit']->update(['concept_key' => 'wallet', 'gift_score' => 40, 'catalog_value_score' => 20]);

        $comparison = app(CompareReplacementCandidateAction::class)->execute(
            $incoming['product']->fresh(),
            $incoming['audit']->fresh(),
            $existing['product']->fresh(),
            $existing['audit']->fresh(),
        );

        $this->assertSame(ReplacementAdvice::NotAReplacement, $comparison['advice']);
        $this->assertSame(ProductStatus::Draft, $existing['product']->fresh()->status);
    }

    public function test_scoped_audit_context_includes_existing_catalog_without_rewriting_history(): void
    {
        $existingRun = $this->acceptedCurationRun();
        $existing = $this->curatedAudit($existingRun, Product::factory()->create(), [
            'concept_key' => 'wallet',
            'outcome' => ProductCurationAuditOutcome::Completed,
            'catalog_value_score' => 55,
        ]);
        $incomingRun = $this->acceptedCurationRun();
        $incoming = $this->curatedAudit($incomingRun, Product::factory()->create(), [
            'concept_key' => 'wallet',
            'outcome' => ProductCurationAuditOutcome::Completed,
            'catalog_value_score' => 40,
        ]);

        $context = app(CalculateRelativeCatalogCurationContextAction::class)->execute($incoming);

        $this->assertContains(
            (int) $existing->product_id,
            $context['peer_product_ids']['concept'] ?? [],
        );
        $this->assertSame(1, ProductCurationAudit::query()->where('run_id', $existingRun->id)->count());
        $this->assertSame(
            $existing->semantic_fingerprint,
            $existing->fresh()->semantic_fingerprint,
        );
        $this->assertNotSame($existingRun->id, $incomingRun->id);
    }

    public function test_premium_gift_intent_fills_medium_gap_without_forcing_father(): void
    {
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $incoming['audit']->update([
            'concept_key' => 'perfume-gift-set',
            'catalog_value_score' => 70,
            'gift_score' => 72,
            'gift_intents' => ['Premium'],
            'peer_product_ids' => ['concept' => []],
        ]);

        $contributions = app(AssessIncomingProductGapContributionAction::class)->execute(
            $incoming['product']->fresh(),
            $incoming['audit']->fresh(),
            ['critical' => [['name' => 'Father']], 'high' => [], 'medium' => [['name' => 'Premium GiftIntent']]],
        );

        $this->assertContains(IncomingCatalogContribution::FillsMediumGap, $contributions);
        $this->assertNotContains(IncomingCatalogContribution::FillsCriticalGap, $contributions);
        $this->assertSame(ProductStatus::Draft, $incoming['product']->fresh()->status);
    }

    public function test_expansion_report_is_advisory_and_does_not_archive_or_publish(): void
    {
        $this->seed(BudgetRangeSeeder::class);
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $existing = $this->retainedDraft(ProductCurationDecision::RemoveCandidate, run: $run);
        $incoming['audit']->update([
            'concept_key' => 'wallet',
            'gift_score' => 78,
            'catalog_value_score' => 64,
            'outcome' => ProductCurationAuditOutcome::Completed,
        ]);
        $existing['audit']->update([
            'concept_key' => 'wallet',
            'gift_score' => 52,
            'catalog_value_score' => 31,
            'outcome' => ProductCurationAuditOutcome::Completed,
        ]);

        $this->artisan('catalog:incoming-expansion-report', [
            '--product' => [(string) $incoming['product']->id],
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame(ProductStatus::Draft, $incoming['product']->fresh()->status);
        $this->assertSame(ProductStatus::Draft, $existing['product']->fresh()->status);
        $this->assertNull($incoming['product']->fresh()->published_at);
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Archived)->count());
        $this->assertSame(0, Product::query()->published()->count());
    }

    public function test_expansion_report_does_not_treat_the_incoming_product_as_its_own_replacement(): void
    {
        $this->seed(BudgetRangeSeeder::class);
        $run = $this->acceptedCurationRun();
        $incoming = $this->retainedDraft(ProductCurationDecision::RemoveCandidate, run: $run);
        $incoming['audit']->update([
            'concept_key' => 'mens-grooming-gift-set',
            'gift_score' => 67,
            'catalog_value_score' => 44,
            'outcome' => ProductCurationAuditOutcome::Completed,
        ]);

        $report = app(BuildIncomingCatalogExpansionReportAction::class)
            ->execute([(int) $incoming['product']->id]);

        $this->assertSame([], $report['replacement_comparisons']);
        $this->assertSame(ProductStatus::Draft, $incoming['product']->fresh()->status);
        $this->assertFalse($report['archived_mutated']);
    }

    public function test_expansion_report_refuses_full_catalog_without_product_ids(): void
    {
        $this->artisan('catalog:incoming-expansion-report')
            ->assertFailed()
            ->expectsOutputToContain('Provide at least one --product ID');
    }
}
