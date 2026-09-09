<?php

namespace Tests\Unit\Actions\CuratedCatalog;

use App\Actions\CuratedCatalog\ApproveCuratedTaxonomyProposalAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsClassificationReviewFixtures;
use Tests\TestCase;

class ApproveCuratedTaxonomyProposalActionTest extends TestCase
{
    use BuildsClassificationReviewFixtures;
    use RefreshDatabase;

    public function test_approve_applies_normalized_taxonomy_and_keeps_draft(): void
    {
        $user = User::factory()->create();
        $fashion = $this->merchandisingCategory('Fashion & Accessories', 'fashion-and-accessories');
        $jewellery = $this->merchandisingCategory('Jewellery', 'jewellery', $fashion->id);
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $birthday = $this->taxonomyValue(Occasion::class, 'Birthday');

        $product = $this->reviewProduct($jewellery, [
            'relationship_ids' => [$husband->id],
            'occasion_ids' => [$birthday->id],
            'confidence' => ['primary_category' => 0.94],
        ]);

        $approved = app(ApproveCuratedTaxonomyProposalAction::class)->execute($product, $user);

        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $approved->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Draft, $approved->status);
        $this->assertNull($approved->published_at);
        $this->assertNotNull($approved->taxonomy_approved_at);
        $this->assertSame($user->id, $approved->taxonomy_approved_by_user_id);
        $this->assertFalse($approved->taxonomy_proposal_pending);
        $this->assertEqualsCanonicalizing(
            [$jewellery->id, $fashion->id],
            $approved->categories()->pluck('categories.id')->all(),
        );
        $this->assertTrue((bool) $approved->categories()->where('categories.id', $jewellery->id)->first()?->pivot->is_primary);
        $this->assertSame([$husband->id], $approved->relationships()->pluck('relationships.id')->all());
        $this->assertSame([$birthday->id], $approved->occasions()->pluck('occasions.id')->all());
        $this->assertNotNull($approved->taxonomy_classification_proposal);
    }

    public function test_stale_version_blocks_approval(): void
    {
        $user = User::factory()->create();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home);
        $product->taxonomy_classification_version = 1;
        $product->save();

        config(['curated_catalog.taxonomy_classification.version' => 2]);

        try {
            app(ApproveCuratedTaxonomyProposalAction::class)->execute($product->fresh(), $user);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('stale', implode(' ', $exception->errors()['taxonomy'] ?? []));
        }

        $this->assertSame(TaxonomyClassificationStatus::Review, $product->fresh()->taxonomy_classification_status);
        $this->assertSame(0, $product->fresh()->categories()->count());
    }

    public function test_changed_content_fingerprint_blocks_approval(): void
    {
        $user = User::factory()->create();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home);
        $product->taxonomy_content_fingerprint = 'changed-content';
        $product->save();

        $this->expectException(ValidationException::class);
        app(ApproveCuratedTaxonomyProposalAction::class)->execute($product->fresh(), $user);
    }

    public function test_changed_hint_fingerprint_blocks_approval(): void
    {
        $user = User::factory()->create();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home);
        $product->taxonomy_relationship_hint_fingerprint = 'changed-hints';
        $product->save();

        $this->expectException(ValidationException::class);
        app(ApproveCuratedTaxonomyProposalAction::class)->execute($product->fresh(), $user);
    }
}
