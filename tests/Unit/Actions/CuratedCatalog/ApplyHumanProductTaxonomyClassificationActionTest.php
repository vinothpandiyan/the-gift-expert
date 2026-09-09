<?php

namespace Tests\Unit\Actions\CuratedCatalog;

use App\Actions\CuratedCatalog\ApplyHumanProductTaxonomyClassificationAction;
use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\RefreshCuratedMerchantProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogSourceListKind;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyDimension;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsClassificationReviewFixtures;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class ApplyHumanProductTaxonomyClassificationActionTest extends TestCase
{
    use BuildsClassificationReviewFixtures;
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    public function test_manual_override_sets_human_overridden_without_ai(): void
    {
        $user = User::factory()->create();
        $electronics = $this->merchandisingCategory('Electronics', 'electronics');
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $living = $this->merchandisingCategory('Living Room', 'living-room', $home->id);
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $father = $this->taxonomyValue(Relationship::class, 'Father');

        $product = $this->reviewProduct($electronics, [], TaxonomyClassificationStatus::AiAccepted);
        $product->categories()->attach($electronics->id, ['is_primary' => true]);
        $product->relationships()->attach($husband->id);

        Http::fake();

        $updated = app(ApplyHumanProductTaxonomyClassificationAction::class)->execute($product, [
            'primary_category_id' => $living->id,
            'relationship_ids' => [$husband->id, $father->id],
        ], $user);

        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $updated->taxonomy_classification_status);
        $this->assertSame($user->id, $updated->taxonomy_approved_by_user_id);
        $this->assertNotNull($updated->taxonomy_approved_at);
        $this->assertEqualsCanonicalizing(
            [$living->id, $home->id],
            $updated->categories()->pluck('categories.id')->all(),
        );
        $this->assertTrue((bool) $updated->categories()->where('categories.id', $living->id)->first()?->pivot->is_primary);
        $this->assertEqualsCanonicalizing(
            [$husband->id, $father->id],
            $updated->relationships()->pluck('relationships.id')->all(),
        );
        Http::assertNothingSent();
    }

    public function test_trusted_hint_can_be_removed_and_refresh_does_not_restore_it(): void
    {
        Http::preventStrayRequests();
        $merchant = $this->configureCuratedAmazonMerchant();
        $user = User::factory()->create();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $father = $this->taxonomyValue(Relationship::class, 'Father');

        $product = $this->reviewProduct($home, [
            'relationship_ids' => [$husband->id],
        ], TaxonomyClassificationStatus::AiAccepted);
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->relationships()->attach($husband->id);

        $link = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $list = CatalogSourceList::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Gifts for Husband',
            'normalized_name' => 'gifts-for-husband',
            'kind' => CatalogSourceListKind::RecipientHint,
            'relationship_id' => $husband->id,
            'is_active' => true,
        ]);
        CatalogProductSource::query()->create([
            'affiliate_link_id' => $link->id,
            'catalog_source_list_id' => $list->id,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'occurrence_count' => 1,
        ]);

        $updated = app(ApplyHumanProductTaxonomyClassificationAction::class)->execute($product->fresh(), [
            'primary_category_id' => $home->id,
            'relationship_ids' => [$father->id],
        ], $user);

        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $updated->taxonomy_classification_status);
        $this->assertSame([$father->id], $updated->relationships()->pluck('relationships.id')->all());

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'New marketplace title',
                'price_amount' => '1499.00',
                'price_currency' => 'INR',
            ]],
        ]))->items[0]->input;

        app(RefreshCuratedMerchantProductAction::class)->execute($merchant, $input);

        $product->refresh();
        $this->assertSame('1499.00', $product->price_amount);
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
        $this->assertSame([$father->id], $product->relationships()->pluck('relationships.id')->all());
        $this->assertTrue(
            CatalogProductSource::query()
                ->where('catalog_source_list_id', $list->id)
                ->exists(),
        );
    }

    public function test_semantic_conflict_rolls_back_pivots_and_status(): void
    {
        $user = User::factory()->create();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $brother = $this->taxonomyValue(Relationship::class, 'Brother');
        $sister = $this->taxonomyValue(Relationship::class, 'Sister');
        $raksha = $this->taxonomyValue(Occasion::class, 'Raksha Bandhan');

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

        $product = $this->reviewProduct($home, [], TaxonomyClassificationStatus::AiAccepted);
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->relationships()->attach($husband->id);

        try {
            app(ApplyHumanProductTaxonomyClassificationAction::class)->execute($product, [
                'primary_category_id' => $home->id,
                'relationship_ids' => [$husband->id],
                'occasion_ids' => [$raksha->id],
            ], $user);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertSame([$husband->id], $product->relationships()->pluck('relationships.id')->all());
        $this->assertSame(0, $product->occasions()->count());
        $this->assertNull($product->taxonomy_approved_by_user_id);
    }
}
