<?php

namespace Tests\Feature\Discovery;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Support\DiscoveryUrl;
use Database\Seeders\BudgetRangeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_and_merged_taxonomy_urls_redirect_to_canonical_destinations(): void
    {
        $this->seed([
            BudgetRangeSeeder::class,
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            InterestSeeder::class,
            ProfessionSeeder::class,
            GiftTypeSeeder::class,
            CategorySeeder::class,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory('gifts-for-him'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::giftIdeas());

        $this->get(DiscoveryUrl::giftIdeasCategory('gifts-for-him/gifts-for-husband'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::relationship('husband'));

        $this->get(DiscoveryUrl::giftIdeasCategory('birthday-gifts'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::occasion('birthday'));

        $this->get(DiscoveryUrl::giftIdeasCategory('personalized-gifts'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::giftType('personalized-gifts'));

        $this->get(DiscoveryUrl::giftType('online-courses'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::giftType('digital-instant-gifts'));

        $this->get(DiscoveryUrl::giftType('ebooks-audiobooks'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::giftType('digital-instant-gifts'));
    }

    public function test_preserved_interest_slugs_still_resolve(): void
    {
        $this->seed([InterestSeeder::class]);

        $this->get(DiscoveryUrl::interest('technology'))
            ->assertOk()
            ->assertSee('Tech & Gadgets');

        $this->get(DiscoveryUrl::interest('food'))
            ->assertOk()
            ->assertSee('Home Chef / Foodie');
    }

    public function test_inactive_taxonomy_is_absent_from_gift_ideas_hub(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get(DiscoveryUrl::giftIdeas())
            ->assertOk()
            ->assertDontSee('href="'.DiscoveryUrl::giftIdeasCategory('gifts-for-him').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftIdeasCategory('birthday-gifts').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftIdeasCategory('personalized-gifts').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::occasion('festival').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::recipientType('adult').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftType('online-courses').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftType('ebooks-audiobooks').'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftType('personalized-gifts').'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeasCategory('home-and-living').'"', false);
    }

    public function test_classification_catalog_omits_deactivated_and_composite_values(): void
    {
        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            InterestSeeder::class,
            ProfessionSeeder::class,
            GiftTypeSeeder::class,
            CategorySeeder::class,
        ]);

        $catalog = app(LoadActiveTaxonomyCatalogAction::class)->execute();
        $forbidden = [
            'gifts-for-him',
            'gifts-for-husband',
            'birthday-gifts',
            'birthday-gifts-for-husband',
            'personalized-gifts',
            'festival',
            'adult',
            'online-courses',
            'ebooks-audiobooks',
        ];

        $slugs = collect($catalog->categories)->pluck('slug')
            ->merge(collect($catalog->occasions)->pluck('slug'))
            ->merge(collect($catalog->recipientTypes)->pluck('slug'))
            ->merge(collect($catalog->giftTypes)->pluck('slug'))
            ->all();

        foreach ($forbidden as $slug) {
            if ($slug === 'personalized-gifts') {
                $this->assertFalse(collect($catalog->categories)->contains(fn (array $row): bool => $row['slug'] === $slug));
                $this->assertTrue(collect($catalog->giftTypes)->contains(fn (array $row): bool => $row['slug'] === $slug));

                continue;
            }

            $this->assertFalse(in_array($slug, $slugs, true), "Forbidden slug [{$slug}] leaked into the AI catalog.");
        }
    }

    public function test_classification_rejects_composite_and_inactive_ids_including_secondary_categories(): void
    {
        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            GiftTypeSeeder::class,
            CategorySeeder::class,
        ]);

        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $giftsForHim = Category::query()->where('slug', 'gifts-for-him')->firstOrFail();
        $personalizedCategory = Category::query()->where('slug', 'personalized-gifts')->firstOrFail();
        $festival = Occasion::query()->where('slug', 'festival')->firstOrFail();
        $adult = RecipientType::query()->where('slug', 'adult')->firstOrFail();
        $onlineCourses = GiftType::query()->where('slug', 'online-courses')->firstOrFail();
        $personalizedGiftType = GiftType::query()->where('slug', 'personalized-gifts')->where('is_active', true)->firstOrFail();

        $result = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $giftsForHim->id,
            'category_ids' => [$giftsForHim->id, $personalizedCategory->id, $home->id],
            'occasion_ids' => [$festival->id],
            'relationship_ids' => [],
            'recipient_type_ids' => [$adult->id],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [$onlineCourses->id, $personalizedGiftType->id],
        ]);

        $this->assertSame($home->id, $result->primaryCategoryId);
        $this->assertSame([$home->id], $result->categoryIds);
        $this->assertSame([], $result->occasionIds);
        $this->assertSame([], $result->recipientTypeIds);
        $this->assertSame([$personalizedGiftType->id], $result->giftTypeIds);
        $this->assertContains('taxonomy_ids_rejected', $result->exceptionCodes);
        $this->assertContains($giftsForHim->id, $result->rejectedIds);
        $this->assertContains($personalizedCategory->id, $result->rejectedIds);
        $this->assertContains($festival->id, $result->rejectedIds);
        $this->assertContains($adult->id, $result->rejectedIds);
        $this->assertContains($onlineCourses->id, $result->rejectedIds);
    }

    public function test_interest_descriptions_are_exposed_to_the_classification_catalog(): void
    {
        $this->seed([InterestSeeder::class]);

        $catalog = app(LoadActiveTaxonomyCatalogAction::class)->execute();
        $technology = collect($catalog->interests)->firstWhere('slug', 'technology');

        $this->assertNotNull($technology);
        $this->assertSame('Tech & Gadgets', $technology['name']);
        $this->assertSame('Tech Lover / Gadget Geek', $technology['description']);
    }
}
