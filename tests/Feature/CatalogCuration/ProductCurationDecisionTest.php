<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Actions\CatalogCuration\ResolveCurationRemediationStatusAction;
use App\Actions\CatalogCuration\ResolveEffectiveProductCurationDecisionAction;
use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class ProductCurationDecisionTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_it_records_a_keep_decision_with_source_audit_traceability(): void
    {
        Log::spy();
        $user = User::factory()->create();
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: 'Strong everyday gift.',
            user: $user,
            catalogRole: CatalogRole::BestValue,
        );

        $this->assertTrue($decision->isCurrent());
        $this->assertTrue($decision->isHumanReviewed());
        $this->assertSame($audit->run_id, $decision->source_audit_run_id);
        $this->assertSame($audit->id, $decision->source_product_curation_audit_id);
        $this->assertSame($user->id, $decision->decided_by_user_id);
        $this->assertSame(ProductCurationRemediationStatus::NotRequired, $decision->remediation_status);
        $this->assertSame(CatalogRole::BestValue, $decision->catalog_role);
        $this->assertTrue(app(ResolveEffectiveProductCurationDecisionAction::class)->isHumanReviewed($audit->product->fresh()));
        Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'catalog_curation.decision_recorded');
    }

    public function test_defer_requires_notes(): void
    {
        $this->expectException(ValidationException::class);

        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Defer,
            reasonCodes: [ProductCurationDecisionReasonCode::NeedsMoreResearch],
            reasonNotes: null,
            user: User::factory()->create(),
        );
    }

    public function test_reclassify_requires_taxonomy_reason_or_notes(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $user = User::factory()->create();
        $proposal = $this->taxonomyProposal(remove: [$attached['relationship']->id]);

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $audit->product,
                audit: $audit,
                decision: ProductCurationDecision::Reclassify,
                reasonCodes: [ProductCurationDecisionReasonCode::WeakGiftFit],
                reasonNotes: null,
                user: $user,
                taxonomyProposal: $proposal,
            );
            $this->fail('Expected reclassify without taxonomy context to fail.');
        } catch (ValidationException) {
            $this->assertSame(0, ProductCurationDecisionRecord::query()->count());
        }

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: null,
            user: $user,
            taxonomyProposal: $proposal,
        );

        $this->assertSame(ProductCurationRemediationStatus::Pending, $decision->remediation_status);
        $this->assertFalse($decision->isHumanReviewed());
        $this->assertSame(
            ProductCurationRemediationStatus::Pending,
            app(ResolveCurationRemediationStatusAction::class)->execute($decision->decision),
        );
    }

    public function test_deactivation_requires_notes(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        $this->expectException(ValidationException::class);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Deactivate,
            reasonCodes: [ProductCurationDecisionReasonCode::CommerceIssue],
            reasonNotes: null,
            user: User::factory()->create(),
        );
    }

    public function test_removal_and_deactivation_require_a_reason(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        $this->expectException(ValidationException::class);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::RemoveCandidate,
            reasonCodes: [],
            reasonNotes: null,
            user: User::factory()->create(),
        );
    }

    public function test_superseding_preserves_history_and_effective_state(): void
    {
        Log::spy();
        $user = User::factory()->create();
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $record = app(RecordProductCurationDecisionAction::class);

        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $first = $record->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: null,
            user: $user,
        );
        $second = $record->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyOccasionIssue],
            reasonNotes: 'Occasion is wrong.',
            user: $user,
            expectedCurrentDecisionId: $first->id,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        $this->assertNull($first->fresh()->current_for_product_id);
        $this->assertTrue($second->isCurrent());
        $this->assertSame($first->id, $second->previous_decision_id);
        $this->assertSame(2, ProductCurationDecisionRecord::query()->where('product_id', $audit->product_id)->count());
        $this->assertSame(
            $second->id,
            app(ResolveEffectiveProductCurationDecisionAction::class)->execute($audit->product->fresh())?->id,
        );
        $this->assertTrue(app(ResolveEffectiveProductCurationDecisionAction::class)->isUnresolved($audit->product->fresh()));
        Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'catalog_curation.decision_superseded');
    }

    public function test_defer_is_not_human_reviewed(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Defer,
            reasonCodes: [ProductCurationDecisionReasonCode::NeedsMoreResearch],
            reasonNotes: 'Need to compare the cluster first.',
            user: User::factory()->create(),
        );

        $this->assertTrue(app(ResolveEffectiveProductCurationDecisionAction::class)->isUnresolved($audit->product->fresh()));
    }

    public function test_stale_decision_requires_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $record = app(RecordProductCurationDecisionAction::class);
        $first = $record->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: null,
            user: $user,
        );
        $second = $record->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::KeepNiche,
            reasonCodes: [ProductCurationDecisionReasonCode::FillsCatalogGap],
            reasonNotes: null,
            user: $user,
            expectedCurrentDecisionId: $first->id,
        );

        try {
            $record->execute(
                product: $audit->product,
                audit: $audit,
                decision: ProductCurationDecision::Feature,
                reasonCodes: [ProductCurationDecisionReasonCode::StrongCatalogValue],
                reasonNotes: null,
                user: $user,
                expectedCurrentDecisionId: $first->id,
            );
            $this->fail('Expected a stale decision conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_current_decision_id', $exception->errors());
        }

        $third = $record->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Feature,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongCatalogValue],
            reasonNotes: null,
            user: $user,
            expectedCurrentDecisionId: $first->id,
            confirmSupersede: true,
        );

        $this->assertTrue($third->isCurrent());
        $this->assertFalse($second->fresh()->isCurrent());
        $this->assertSame($second->id, $third->previous_decision_id);
        $this->assertSame($audit->id, $third->source_product_curation_audit_id);
    }

    public function test_catalog_role_is_rejected_for_removal_decisions(): void
    {
        $this->expectException(ValidationException::class);

        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Deactivate,
            reasonCodes: [ProductCurationDecisionReasonCode::RedundantConcept],
            reasonNotes: 'Should leave the catalog.',
            user: User::factory()->create(),
            catalogRole: CatalogRole::BestOverall,
        );
    }
}
