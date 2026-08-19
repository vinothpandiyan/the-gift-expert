<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateProductTaxonomyClassificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_valid_active_ids_and_requires_an_acceptable_primary(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $electronics = $this->category('Electronics', 'electronics');

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $home->id,
            'category_ids' => [$home->id, $electronics->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertSame($home->id, $result->primaryCategoryId);
        $this->assertSame([$home->id, $electronics->id], $result->categoryIds);
        $this->assertSame([], $result->exceptionCodes);
    }

    public function test_it_drops_invented_inactive_and_trashed_ids(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $inactive = $this->category('Inactive', 'inactive-cat', active: false);
        $trashed = $this->category('Trashed', 'trashed-cat');
        $trashed->delete();

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $home->id,
            'category_ids' => [$home->id, $inactive->id, $trashed->id, 999999],
            'occasion_ids' => [888888],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertSame([$home->id], $result->categoryIds);
        $this->assertContains('taxonomy_ids_rejected', $result->exceptionCodes);
        $this->assertContains(999999, $result->rejectedIds);
        $this->assertContains($inactive->id, $result->rejectedIds);
        $this->assertContains($trashed->id, $result->rejectedIds);
    }

    public function test_it_enforces_interest_and_relationship_caps(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $interests = [];

        foreach (['coffee', 'tech', 'travel', 'music'] as $slug) {
            $interests[] = Interest::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'is_active' => true,
                'sort_order' => 1,
            ]);
        }

        $relationships = [];

        foreach (range(1, 5) as $i) {
            $relationships[] = Relationship::query()->create([
                'name' => 'Rel '.$i,
                'slug' => 'rel-'.$i,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $home->id,
            'category_ids' => [$home->id],
            'occasion_ids' => [],
            'relationship_ids' => array_map(fn ($row) => $row->id, $relationships),
            'recipient_type_ids' => [],
            'interest_ids' => array_map(fn ($row) => $row->id, $interests),
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertCount(3, $result->interestIds);
        $this->assertCount(4, $result->relationshipIds);
        $this->assertContains('taxonomy_ids_rejected', $result->exceptionCodes);
    }

    public function test_it_rejects_mapped_and_intent_shaped_primary_categories(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $page = SeoLandingPage::factory()->create();
        $mapped = $this->category('Birthday Gifts for Husband', 'birthday-gifts-for-husband');
        $mapped->canonical_seo_landing_page_id = $page->id;
        $mapped->save();

        Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true, 'sort_order' => 1]);
        $birthdayGifts = $this->category('Birthday Gifts', 'birthday-gifts');
        $giftsForHim = $this->category('Gifts for Him', 'gifts-for-him');

        $validator = app(ValidateProductTaxonomyClassificationAction::class);

        $mappedResult = $validator->execute([
            'primary_category_id' => $mapped->id,
            'category_ids' => [$mapped->id, $home->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertSame($home->id, $mappedResult->primaryCategoryId);

        $birthdayResult = $validator->execute([
            'primary_category_id' => $birthdayGifts->id,
            'category_ids' => [$birthdayGifts->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertNull($birthdayResult->primaryCategoryId);
        $this->assertContains('missing_primary_category', $birthdayResult->exceptionCodes);

        $himResult = $validator->execute([
            'primary_category_id' => $giftsForHim->id,
            'category_ids' => [$giftsForHim->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertNull($himResult->primaryCategoryId);
    }

    public function test_it_keeps_valid_profession_and_gift_type_ids_without_keyword_rules(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $doctor = Profession::query()->create(['name' => 'Doctor', 'slug' => 'doctor', 'is_active' => true, 'sort_order' => 1]);
        $giftCards = GiftType::query()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'is_active' => true, 'sort_order' => 1]);

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $home->id,
            'category_ids' => [$home->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [$doctor->id],
            'gift_type_ids' => [$giftCards->id],
        ]);

        $this->assertSame([$doctor->id], $result->professionIds);
        $this->assertSame([$giftCards->id], $result->giftTypeIds);
    }

    public function test_recipient_type_cap_is_enforced(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $ids = [];

        foreach (['kids', 'teen', 'adult', 'senior'] as $i => $slug) {
            $ids[] = RecipientType::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'is_active' => true,
                'sort_order' => $i,
            ])->id;
        }

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $home->id,
            'category_ids' => [$home->id],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => $ids,
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ]);

        $this->assertCount(3, $result->recipientTypeIds);
    }

    private function category(string $name, string $slug, bool $active = true): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => $active,
            'sort_order' => 1,
        ]);
    }
}
