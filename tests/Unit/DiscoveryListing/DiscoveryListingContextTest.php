<?php

namespace Tests\Unit\DiscoveryListing;

use App\DiscoveryListing\DiscoveryListingContext;
use App\Enums\TaxonomyDimension;
use App\Models\BudgetRange;
use App\Models\Interest;
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

    public function test_relationship_filter_order_puts_occasion_and_category_before_budget(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->assertSame(
            [
                'occasion',
                'category',
                'interest',
                'gift_type',
                'budget',
            ],
            DiscoveryListingContext::forRelationship($husband)->availableDimensions,
        );
    }

    public function test_gift_ideas_keeps_recipient_and_occasion_ahead_of_budget(): void
    {
        $this->assertSame(
            [
                'relationship',
                'occasion',
                'category',
                'interest',
                'gift_type',
                'profession',
                'recipient',
                'budget',
            ],
            DiscoveryListingContext::forGiftIdeas()->availableDimensions,
        );
    }
}
