<?php

namespace Tests\Unit\DiscoveryListing;

use App\DiscoveryListing\DiscoveryListingContext;
use App\Enums\TaxonomyDimension;
use App\Models\BudgetRange;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryListingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_taxonomy_contexts_expose_fixed_ids_and_omit_budget(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $tech = Interest::query()->create([
            'name' => 'Tech & Gadgets',
            'slug' => 'technology',
            'is_active' => true,
        ]);
        $budget = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => 0,
            'max_amount' => 500,
            'is_active' => true,
        ]);

        $context = new DiscoveryListingContext(
            surface: 'seo_landing',
            fixedFilters: [
                'relationship_id' => $husband->id,
                'budget_range_id' => $budget->id,
                'interest_ids' => [$tech->id],
            ],
            availableDimensions: ['occasion'],
            browseContext: 'test',
        );

        $this->assertEqualsCanonicalizing(
            [
                [
                    'dimension' => TaxonomyDimension::Relationship,
                    'id' => $husband->id,
                ],
                [
                    'dimension' => TaxonomyDimension::Interest,
                    'id' => $tech->id,
                ],
            ],
            $context->semanticTaxonomyContexts(),
        );
    }

    public function test_relationship_listing_exposes_the_fixed_relationship_id(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->assertSame(
            [
                [
                    'dimension' => TaxonomyDimension::Relationship,
                    'id' => $husband->id,
                ],
            ],
            DiscoveryListingContext::forRelationship($husband)->semanticTaxonomyContexts(),
        );
    }

    public function test_gender_specific_relationship_omits_recipient_gender_and_orders_primary_filters(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->assertSame(
            ['budget', 'occasion', 'interest', 'gift_type', 'category'],
            DiscoveryListingContext::forRelationship($husband)->availableDimensions,
        );
    }

    public function test_gender_neutral_relationship_keeps_recipient_gender_after_primary_filters(): void
    {
        $friends = Relationship::query()->create([
            'name' => 'Friends',
            'slug' => 'friends',
            'is_active' => true,
        ]);

        $this->assertSame(
            ['budget', 'occasion', 'interest', 'gift_type', 'category', 'gender'],
            DiscoveryListingContext::forRelationship($friends)->availableDimensions,
        );
    }

    public function test_relationship_gender_follows_config_profile_not_slug_lists(): void
    {
        $custom = Relationship::query()->create([
            'name' => 'Pen Pal',
            'slug' => 'pen-pal',
            'is_active' => true,
        ]);

        $this->assertContains('gender', DiscoveryListingContext::forRelationship($custom)->availableDimensions);

        config(['recipient_eligibility.relationships.pen-pal' => ['wave' => null, 'gender' => 'female', 'infer' => false]]);

        $this->assertNotContains('gender', DiscoveryListingContext::forRelationship($custom)->availableDimensions);
    }

    public function test_gift_ideas_orders_primary_filters_before_additional_filters(): void
    {
        $this->assertSame(
            [
                'budget',
                'occasion',
                'interest',
                'gift_type',
                'category',
                'relationship',
                'profession',
                'recipient',
                'gender',
            ],
            DiscoveryListingContext::forGiftIdeas()->availableDimensions,
        );
    }

    public function test_occasion_listing_keeps_additional_filters_after_primary_filters(): void
    {
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $this->assertSame(
            ['budget', 'interest', 'gift_type', 'category', 'relationship', 'recipient', 'gender'],
            DiscoveryListingContext::forOccasion($birthday)->availableDimensions,
        );
    }
}
