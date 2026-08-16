<?php

namespace Tests\Unit\Support;

use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\SeoLandingPageEditorial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLandingPageEditorialTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_only_duplicates_taxonomy_intent(): void
    {
        $this->assertTrue(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'relationship_id' => 1,
            'interest_ids' => [],
        ]));
    }

    public function test_single_interest_only_duplicates_taxonomy_intent(): void
    {
        $this->assertTrue(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'interest_ids' => [4],
        ]));
    }

    public function test_two_interests_do_not_duplicate_taxonomy_intent(): void
    {
        $this->assertFalse(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'interest_ids' => [4, 5],
        ]));
    }

    public function test_relationship_and_occasion_do_not_duplicate_taxonomy_intent(): void
    {
        $this->assertFalse(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'relationship_id' => 1,
            'occasion_id' => 2,
        ]));
    }

    public function test_relationship_plus_budget_does_not_duplicate_taxonomy_intent(): void
    {
        $this->assertFalse(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'relationship_id' => 1,
            'budget_range_id' => 3,
        ]));
    }

    public function test_budget_only_does_not_duplicate_taxonomy_intent(): void
    {
        $this->assertFalse(SeoLandingPageEditorial::duplicatesTaxonomyIntent([
            'budget_range_id' => 3,
            'interest_ids' => [],
        ]));
    }

    public function test_taxonomy_slug_taken_matches_relationship_and_category_slugs(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband']);
        Category::query()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'max_amount' => '499.99',
            'currency' => 'INR',
        ]);

        $this->assertTrue(SeoLandingPageEditorial::taxonomySlugTaken('husband'));
        $this->assertTrue(SeoLandingPageEditorial::taxonomySlugTaken('electronics'));
        $this->assertFalse(SeoLandingPageEditorial::taxonomySlugTaken('gifts-for-husband'));
        $this->assertFalse(SeoLandingPageEditorial::taxonomySlugTaken('under-500'));
    }

    public function test_find_published_duplicate_matches_interest_set_regardless_of_order(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband']);
        $coffee = Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee']);
        $tech = Interest::query()->create(['name' => 'Technology', 'slug' => 'technology']);

        $existing = SeoLandingPage::factory()->published()->create([
            'heading' => 'Existing',
            'relationship_id' => $husband->id,
        ]);
        $existing->interests()->attach([$tech->id, $coffee->id]);

        $candidate = SeoLandingPage::factory()->create([
            'heading' => 'Candidate',
            'relationship_id' => $husband->id,
        ]);
        $candidate->interests()->attach([$coffee->id, $tech->id]);

        $duplicate = SeoLandingPageEditorial::findPublishedDuplicate($candidate);

        $this->assertNotNull($duplicate);
        $this->assertTrue($duplicate->is($existing));
    }

    public function test_find_published_duplicate_ignores_drafts_and_different_interests(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband']);
        $birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday']);
        $coffee = Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee']);

        SeoLandingPage::factory()->draft()->create([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);

        $published = SeoLandingPage::factory()->published()->create([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);
        $published->interests()->attach($coffee);

        $candidate = SeoLandingPage::factory()->create([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);

        $this->assertNull(SeoLandingPageEditorial::findPublishedDuplicate($candidate));
    }
}
