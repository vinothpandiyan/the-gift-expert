<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\ApplyHumanCurationReclassificationAction;
use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Actions\CuratedCatalog\ShouldReclassifyCuratedMerchantProductAction;
use App\CatalogCuration\HumanTaxonomyProposal;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyDimension;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class ProductCurationReclassificationTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_reclassify_is_required_before_execution(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $this->attachMerchandisingTaxonomy($audit->product);
        $keep = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [
                ProductCurationDecisionReasonCode::StrongPracticalValue,
                ProductCurationDecisionReasonCode::AuditAnomaly,
            ],
            reasonNotes: 'Current taxonomy is valid.',
            user: User::factory()->create(),
        );

        $this->expectException(ValidationException::class);

        app(ApplyHumanCurationReclassificationAction::class)->execute($keep, User::factory()->create());
    }

    public function test_structured_delta_is_required_and_inactive_taxonomy_is_rejected(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $this->attachMerchandisingTaxonomy($audit->product);
        $user = User::factory()->create();

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $audit->product,
                audit: $audit,
                decision: ProductCurationDecision::Reclassify,
                reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
                reasonNotes: 'Needs a real delta.',
                user: $user,
            );
            $this->fail('Expected RECLASSIFY without a proposal to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('taxonomy_proposal', $exception->errors());
        }

        $inactive = Relationship::query()->create([
            'name' => 'Retired Label',
            'slug' => 'retired-label-'.uniqid(),
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Cannot add an inactive relationship.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(add: [$inactive->id]),
        );
    }

    public function test_authoritative_conflict_is_rejected_and_minimal_delta_preserves_unrelated_dimensions(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, null, []);
        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband-'.uniqid(),
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday-'.uniqid(),
            'is_active' => true,
        ]);
        $travel = Interest::query()->create([
            'name' => 'Travel',
            'slug' => 'travel-'.uniqid(),
            'is_active' => true,
        ]);
        $hamper = GiftType::query()->create([
            'name' => 'Hampers/Gift Sets',
            'slug' => 'hampers-'.uniqid(),
            'is_active' => true,
        ]);
        $raksha = Occasion::query()->create([
            'name' => 'Raksha Bandhan',
            'slug' => 'raksha-bandhan-'.uniqid(),
            'is_active' => true,
        ]);
        $sister = Relationship::query()->create([
            'name' => 'Sister',
            'slug' => 'sister-'.uniqid(),
            'is_active' => true,
        ]);

        $audit->product->relationships()->sync([$attached['relationship']->id, $husband->id]);
        $audit->product->occasions()->sync([$birthday->id]);
        $audit->product->interests()->sync([$travel->id]);
        $audit->product->giftTypes()->sync([$hamper->id]);

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $attached['relationship']->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $sister->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $audit->product,
                audit: $audit,
                decision: ProductCurationDecision::Reclassify,
                reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyOccasionIssue],
                reasonNotes: 'Raksha Bandhan conflicts with Husband.',
                user: $user,
                taxonomyProposal: HumanTaxonomyProposal::fromArray([
                    'occasions' => ['add' => [$raksha->id], 'remove' => []],
                ]),
            );
            $this->fail('Expected an authoritative conflict to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Brother is misleading on this gift.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        $this->assertSame(ProductCurationRemediationStatus::Pending, $decision->remediation_status);
        $this->assertSame([$attached['relationship']->id, $husband->id], $audit->product->fresh()->relationships()->pluck('relationships.id')->sort()->values()->all());

        $completed = app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);
        $product = $audit->product->fresh();

        $this->assertSame(ProductCurationRemediationStatus::Completed, $completed->remediation_status);
        $this->assertSame([$husband->id], $product->relationships()->pluck('relationships.id')->all());
        $this->assertSame([$birthday->id], $product->occasions()->pluck('occasions.id')->all());
        $this->assertSame([$travel->id], $product->interests()->pluck('interests.id')->all());
        $this->assertSame([$hamper->id], $product->giftTypes()->pluck('gift_types.id')->all());
        $this->assertSame($attached['category']->id, $product->categories()->wherePivot('is_primary', true)->first()?->id);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
        $this->assertSame($user->id, $product->taxonomy_approved_by_user_id);
        $this->assertSame(1, ProductCurationAudit::query()->count());
    }

    public function test_transaction_rollback_leaves_no_partial_taxonomy_state(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, null, []);
        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband-rollback-'.uniqid(),
            'is_active' => true,
        ]);
        $audit->product->relationships()->sync([$attached['relationship']->id, $husband->id]);
        $user = User::factory()->create();
        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Remove Brother only.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        ProductCurationDecisionRecord::saving(function (ProductCurationDecisionRecord $record): void {
            if ($record->remediation_status === ProductCurationRemediationStatus::Completed) {
                throw new RuntimeException('forced remediation failure');
            }
        });

        try {
            app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);
            $this->fail('Expected the forced remediations failure.');
        } catch (RuntimeException) {
            // The decision and taxonomy must remain unchanged.
        }

        $product = $audit->product->fresh();
        $this->assertEqualsCanonicalizing(
            [$attached['relationship']->id, $husband->id],
            $product->relationships()->pluck('relationships.id')->all(),
        );
        $this->assertSame(ProductCurationRemediationStatus::Pending, $decision->fresh()->remediation_status);
        $this->assertNotSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
    }

    public function test_human_reclassification_locks_automated_classification(): void
    {
        $run = $this->acceptedCurationRun();
        $product = Product::factory()->create([
            'status' => ProductStatus::Published,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ]);
        $audit = $this->curatedAudit($run, $product);
        $attached = $this->attachMerchandisingTaxonomy($product);
        $user = User::factory()->create();
        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Brother should not remain.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);
        $product = $product->fresh();

        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Published, $product->status);

        $gate = app(ShouldReclassifyCuratedMerchantProductAction::class);
        $this->assertFalse($gate->execute($product)->shouldReclassify);
        $this->assertSame('human_locked', $gate->execute($product)->reason);
        $this->assertTrue($gate->execute($product, force: true)->shouldReclassify);
        $this->assertSame('forced', $gate->execute($product, force: true)->reason);
    }

    public function test_archived_products_are_not_reclassified(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $audit->product->update(['status' => ProductStatus::Archived]);
        $user = User::factory()->create();
        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Would remove Brother if this were still live.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        $this->expectException(ValidationException::class);

        app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);
    }

    public function test_execution_does_not_rewrite_historical_audit_rows(): void
    {
        Log::spy();
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $attached = $this->attachMerchandisingTaxonomy($audit->product);
        $before = $audit->fresh()->toArray();
        $user = User::factory()->create();
        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Remove a misleading relationship.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);

        $this->assertEquals($before, $audit->fresh()->toArray());
        $this->assertSame(1, ProductCurationAudit::query()->count());
        Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'catalog_curation.product_reclassified');
    }
}
