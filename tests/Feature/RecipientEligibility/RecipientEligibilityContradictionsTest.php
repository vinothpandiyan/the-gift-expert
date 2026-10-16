<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class RecipientEligibilityContradictionsTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    private Product $clean;

    private Product $missingGender;

    private Product $femaleTaggedHusband;

    private Product $returnGiftTaggedHusband;

    private Product $babyShowerOnlyTaggedHusband;

    private Product $babyShowerAndBirthdayTaggedHusband;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();

        $this->clean = $this->eligibilityProduct('Clean Husband Gift', ['relationships' => ['husband'], 'occasions' => ['birthday'], 'genders' => ['male']]);
        $this->missingGender = $this->eligibilityProduct('Missing Gender Husband Gift', ['relationships' => ['husband'], 'occasions' => ['birthday']]);
        $this->femaleTaggedHusband = $this->eligibilityProduct('Birth Flower Keychain', ['relationships' => ['husband'], 'occasions' => ['birthday'], 'genders' => ['female']]);
        $this->returnGiftTaggedHusband = $this->eligibilityProduct('Return Hamper', ['relationships' => ['husband'], 'occasions' => ['birthday'], 'genders' => ['unisex'], 'gift_types' => ['return-gifts']]);
        $this->babyShowerOnlyTaggedHusband = $this->eligibilityProduct('Baby Rattle', ['relationships' => ['husband'], 'occasions' => ['baby-shower'], 'genders' => ['unisex']]);
        $this->babyShowerAndBirthdayTaggedHusband = $this->eligibilityProduct('Gift For Dads', ['relationships' => ['husband'], 'occasions' => ['baby-shower', 'birthday'], 'genders' => ['unisex']]);
    }

    public function test_shadow_and_off_modes_never_change_explicit_results(): void
    {
        $this->enableRecipientEligibility([
            'explicit_contradictions' => 'off',
            'relationships.husband.infer' => false,
        ]);
        $off = $this->husbandIds();

        $this->enableRecipientEligibility(['explicit_contradictions' => 'shadow', 'relationships.husband.infer' => false]);
        $shadow = $this->husbandIds();

        $this->assertCount(6, $off);
        $this->assertEqualsCanonicalizing($off, $shadow);
    }

    public function test_enforce_drops_known_contradictions_but_never_missing_gender(): void
    {
        $this->enableRecipientEligibility([
            'explicit_contradictions' => 'enforce',
            'relationships.husband.infer' => false,
        ]);

        $ids = $this->husbandIds();

        $this->assertContains($this->clean->id, $ids);
        $this->assertContains($this->missingGender->id, $ids, 'missing gender is not a known gender mismatch');
        $this->assertContains($this->babyShowerAndBirthdayTaggedHusband->id, $ids, 'one applicable occasion is enough');
        $this->assertNotContains($this->femaleTaggedHusband->id, $ids);
        $this->assertNotContains($this->returnGiftTaggedHusband->id, $ids);
        $this->assertNotContains($this->babyShowerOnlyTaggedHusband->id, $ids);
    }

    public function test_enforce_applies_to_the_total_and_to_relationship_facet_counts(): void
    {
        $this->enableRecipientEligibility([
            'explicit_contradictions' => 'enforce',
            'relationships.husband.infer' => false,
        ]);

        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $context = DiscoveryListingContext::forOccasion($birthday);
        $state = new DiscoveryListingQueryState(relationshipSlugs: ['husband']);

        $total = app(QueryDiscoveryListingProductsAction::class)->count($context, $state);
        $chip = app(ResolveDiscoveryFilterOptionsAction::class)
            ->execute($context, new DiscoveryListingQueryState)['relationship']
            ->firstWhere('slug', 'husband');

        $this->assertSame(3, $total);
        $this->assertSame($total, $chip?->count);
    }

    public function test_contradictions_apply_only_to_wave_one_relationships(): void
    {
        $brotherGift = $this->eligibilityProduct('Women Bag For Brother', ['relationships' => ['brother'], 'occasions' => ['birthday'], 'genders' => ['female']]);

        $this->enableRecipientEligibility(['explicit_contradictions' => 'enforce']);

        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'brother')->firstOrFail());
        $ids = app(QueryDiscoveryListingProductsAction::class)
            ->execute($context, new DiscoveryListingQueryState)
            ->getCollection()->pluck('id')->all();

        $this->assertContains($brotherGift->id, $ids);
    }

    public function test_enforcement_requires_the_master_switch(): void
    {
        $this->enableRecipientEligibility([
            'enabled' => false,
            'explicit_contradictions' => 'enforce',
        ]);

        $this->assertContains($this->femaleTaggedHusband->id, $this->husbandIds());
    }

    /**
     * @return list<int>
     */
    private function husbandIds(): array
    {
        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());

        return app(QueryDiscoveryListingProductsAction::class)
            ->execute($context, new DiscoveryListingQueryState, 1, 5)
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
