<?php

namespace Tests\Unit\Actions\PublicationReadiness;

use App\Actions\CuratedCatalog\BuildCuratedRelationshipHintFingerprintAction;
use App\Actions\CuratedCatalog\BuildCuratedTaxonomyContentFingerprintAction;
use App\Actions\PublicationReadiness\DiagnosePublicationReadinessProductAction;
use App\Actions\PublicationReadiness\RemediatePublicationReadinessProductAction;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\PublicationReadinessDiagnosisCode;
use App\Enums\PublicationReadinessRemediation;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsClassificationReviewFixtures;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class DiagnoseAndRemediatePublicationReadinessTest extends TestCase
{
    use BuildsClassificationReviewFixtures;
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_unapplied_review_proposal_is_lifecycle_only_and_approval_makes_it_publish_ready(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->blockedRetainedReview($home);
        $user = User::factory()->create();

        $diagnosis = app(DiagnosePublicationReadinessProductAction::class)->execute($product);

        $this->assertNotNull($diagnosis);
        $this->assertSame(PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly, $diagnosis->code);
        $this->assertSame(PublicationReadinessRemediation::ApproveStoredProposal, $diagnosis->remediation);

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product, $user);

        $fresh = $product->fresh();
        $this->assertTrue($result->publishReady);
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $fresh->taxonomy_classification_status);
        $this->assertTrue((bool) $fresh->categories()->where('categories.id', $home->id)->first()?->pivot->is_primary);
        $this->assertFalse($result->published);
        $this->assertFalse($result->archived);
    }

    public function test_over_cap_review_proposal_is_human_classified_from_accepted_remainder(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $relationships = [];
        foreach (['Husband', 'Boyfriend', 'Wife', 'Girlfriend', 'Newlyweds'] as $name) {
            $relationships[] = $this->taxonomyValue(Relationship::class, $name);
        }
        $product = $this->blockedRetainedReview($home, [
            'relationship_ids' => array_map(fn ($relationship): int => (int) $relationship->id, $relationships),
        ]);

        $diagnosis = app(DiagnosePublicationReadinessProductAction::class)->execute($product);

        $this->assertSame(PublicationReadinessRemediation::HumanClassify, $diagnosis->remediation);
        $this->assertCount(5, $diagnosis->suggestedTaxonomy['relationship_ids'] ?? []);

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product, User::factory()->create());

        $this->assertTrue($result->publishReady);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->fresh()->taxonomy_classification_status);
        $this->assertCount(5, $product->fresh()->relationships);
    }

    public function test_preview_does_not_mutate_taxonomy_or_publication(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->blockedRetainedReview($home);
        $user = User::factory()->create();

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product, $user, apply: false);

        $fresh = $product->fresh();
        $this->assertFalse($result->publishReady);
        $this->assertSame(TaxonomyClassificationStatus::Review, $fresh->taxonomy_classification_status);
        $this->assertSame(0, $fresh->categories()->count());
        $this->assertSame(ProductStatus::Draft, $fresh->status);
    }

    public function test_low_confidence_unknown_contents_remain_taxonomy_ambiguity(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->blockedRetainedReview($home, [
            'confidence' => ['primary_category' => 0.42, 'gift_types' => 0.95],
            'reasoning_summary' => [
                'primary_category' => 'The title identifies a romantic gift combo but does not reveal its contents; Home & Living is the broadest available merchandising category.',
            ],
        ]);

        $diagnosis = app(DiagnosePublicationReadinessProductAction::class)->execute($product);

        $this->assertSame(PublicationReadinessDiagnosisCode::TaxonomyAmbiguity, $diagnosis->code);
        $this->assertSame(PublicationReadinessRemediation::LeaveBlocked, $diagnosis->remediation);

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product, User::factory()->create());

        $this->assertFalse($result->publishReady);
        $this->assertSame(TaxonomyClassificationStatus::Review, $product->fresh()->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_failed_gift_card_uses_current_gift_cards_category_without_publishing(): void
    {
        $giftCards = $this->merchandisingCategory('Gift Cards & Vouchers', 'gift-cards-vouchers');
        $digital = GiftType::query()->create([
            'name' => 'Digital / Instant Gifts',
            'slug' => 'digital-instant-gifts',
            'is_active' => true,
        ]);
        $cardType = GiftType::query()->create([
            'name' => 'Gift Cards',
            'slug' => 'gift-cards',
            'is_active' => true,
        ]);
        $birthday = $this->taxonomyValue(Occasion::class, 'Birthday');
        $product = $this->blockedRetainedReview($giftCards, [
            'primary_category_id' => null,
            'category_ids' => [],
            'gift_type_ids' => [$digital->id, $cardType->id],
            'occasion_ids' => [$birthday->id],
            'review_reasons' => ['taxonomy_gap_blocking', 'missing_primary_category'],
            'taxonomy_gap' => [
                'detected' => true,
                'severity' => 'blocking',
                'suggested_concept' => 'Gift Cards / Digital Gifts',
                'explanation' => 'The product is a digital gift card and cannot be represented honestly by any available merchandising Category.',
            ],
            'confidence' => ['primary_category' => 0.98, 'gift_types' => 0.99],
        ], TaxonomyClassificationStatus::Failed);
        $product->name = 'Amazon Pay Gift Card (Digital)';
        $product->save();

        $diagnosis = app(DiagnosePublicationReadinessProductAction::class)->execute($product->fresh());

        $this->assertSame(PublicationReadinessDiagnosisCode::HistoricalStaleState, $diagnosis->code);
        $this->assertSame(PublicationReadinessRemediation::HumanClassify, $diagnosis->remediation);
        $this->assertSame($giftCards->id, $diagnosis->suggestedTaxonomy['primary_category_id'] ?? null);

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product->fresh(), User::factory()->create());

        $fresh = $product->fresh();
        $this->assertTrue($result->publishReady);
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $fresh->taxonomy_classification_status);
        $this->assertTrue((bool) $fresh->categories()->where('categories.id', $giftCards->id)->first()?->pivot->is_primary);
        $this->assertEqualsCanonicalizing([$digital->id, $cardType->id], $fresh->giftTypes()->pluck('gift_types.id')->all());
        $this->assertSame([$birthday->id], $fresh->occasions()->pluck('occasions.id')->all());
        $this->assertSame([], $fresh->relationships()->pluck('relationships.id')->all());
        $this->assertFalse($result->published);
    }

    public function test_trusted_hint_conflict_drops_incompatible_hints_and_keeps_title_fit(): void
    {
        $beauty = $this->merchandisingCategory('Beauty & Grooming', 'beauty-and-grooming');
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $boyfriend = $this->taxonomyValue(Relationship::class, 'Boyfriend');
        $brother = $this->taxonomyValue(Relationship::class, 'Brother');
        $sister = $this->taxonomyValue(Relationship::class, 'Sister');
        $raksha = $this->taxonomyValue(Occasion::class, 'Raksha Bandhan');
        $this->allowRaksha($raksha, $brother, $sister);

        $product = $this->blockedRetainedReview($beauty, [
            'relationship_ids' => [$husband->id, $boyfriend->id, $brother->id],
            'occasion_ids' => [$raksha->id],
            'source_relationship_hint_ids' => [$husband->id, $boyfriend->id],
            'review_reasons' => ['trusted_source_semantic_conflict'],
            'confidence' => ['primary_category' => 0.98],
        ]);

        $diagnosis = app(DiagnosePublicationReadinessProductAction::class)->execute($product);

        $this->assertSame(PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly, $diagnosis->code);
        $this->assertSame(PublicationReadinessRemediation::HumanClassify, $diagnosis->remediation);
        $this->assertSame([$brother->id], $diagnosis->suggestedTaxonomy['relationship_ids'] ?? null);

        $result = app(RemediatePublicationReadinessProductAction::class)->execute($product, User::factory()->create());

        $fresh = $product->fresh();
        $this->assertTrue($result->publishReady);
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertSame([$brother->id], $fresh->relationships()->pluck('relationships.id')->all());
        $this->assertSame([$raksha->id], $fresh->occasions()->pluck('occasions.id')->all());
        $this->assertFalse($result->archived);
    }

    public function test_command_preview_is_read_only(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->blockedRetainedReview($home);

        $this->artisan('catalog:remediate-readiness', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"mode": "preview"');

        $this->assertSame(TaxonomyClassificationStatus::Review, $product->fresh()->taxonomy_classification_status);
        $this->assertSame(0, $product->fresh()->categories()->count());
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_command_execute_does_not_publish(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->blockedRetainedReview($home);
        User::factory()->create();

        $this->artisan('catalog:remediate-readiness', ['--execute' => true])
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $fresh->taxonomy_classification_status);
        $this->assertSame(0, Product::query()->published()->count());
    }

    /**
     * @param  array<string, mixed>  $proposalOverrides
     */
    private function blockedRetainedReview(
        Category $primary,
        array $proposalOverrides = [],
        TaxonomyClassificationStatus $status = TaxonomyClassificationStatus::Review,
    ): Product {
        $bundle = $this->retainedDraft(ProductCurationDecision::Keep, [
            'taxonomy_classification_status' => $status,
        ]);
        $product = $bundle['product'];
        $proposal = array_merge([
            'primary_category_id' => $primary->id,
            'category_ids' => [$primary->id],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'occasion_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
            'confidence' => ['primary_category' => 0.78, 'gift_types' => 0.90],
            'reasoning_summary' => ['primary_category' => 'Matches the merchandising family.'],
            'warnings' => ['low_primary_category_confidence'],
            'review_reasons' => ['low_primary_category_confidence'],
            'classification_version' => (int) config('curated_catalog.taxonomy_classification.version', 1),
            'source_title' => $product->name,
            'source_relationship_hint_ids' => [],
        ], $proposalOverrides);

        $product->categories()->sync([]);
        $product->taxonomy_classification_status = $status;
        $product->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $product->taxonomy_review_reasons = $proposal['review_reasons'];
        $product->taxonomy_classification_proposal = $proposal;
        $product->taxonomy_content_fingerprint = app(BuildCuratedTaxonomyContentFingerprintAction::class)->execute($product);
        $product->taxonomy_relationship_hint_fingerprint = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product);
        $product->save();

        return $product->fresh();
    }

    private function allowRaksha(Occasion $raksha, Relationship $brother, Relationship $sister): void
    {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $brother->id,
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
    }
}
