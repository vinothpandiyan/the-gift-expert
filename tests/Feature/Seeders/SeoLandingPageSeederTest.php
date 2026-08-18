<?php

namespace Tests\Feature\Seeders;

use App\Enums\SeoLandingPageStatus;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\SeoLandingPageCandidateCatalog;
use App\Support\SeoLandingPageEditorial;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLandingPageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_draft_landing_pages_are_seeded_without_publishing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $approvedSlugs = [
            'anniversary-gifts-for-husband',
            'birthday-gifts-for-wife',
            'anniversary-gifts-for-wife',
            'birthday-gifts-for-boyfriend',
            'anniversary-gifts-for-boyfriend',
            'birthday-gifts-for-girlfriend',
            'birthday-gifts-for-dad',
            'birthday-gifts-for-mom',
            'birthday-gifts-for-brother',
            'gifts-for-husband-who-loves-coffee',
            'gifts-for-husband-who-loves-technology',
            'birthday-gifts-for-coffee-lovers',
            'birthday-gifts-for-tech-lovers',
        ];

        foreach ($approvedSlugs as $slug) {
            $page = SeoLandingPage::query()->where('slug', $slug)->first();

            $this->assertNotNull($page, $slug);
            $this->assertSame(SeoLandingPageStatus::Draft, $page->status);
            $this->assertFalse($page->is_indexable);
            $this->assertFalse($page->include_in_sitemap);
            $this->assertNull($page->category_id);
            $this->assertGreaterThanOrEqual(2, SeoLandingPageCandidateCatalog::dimensionCount(
                SeoLandingPageEditorial::productFilters($page),
            ), $slug);
            $this->assertNotSame('', (string) $page->intro_content);
            $this->assertNotSame('', (string) $page->body_content);
        }

        $this->assertSame(1, SeoLandingPage::query()->where('slug', 'birthday-gifts-for-husband')->count());
        $this->assertSame(
            SeoLandingPageStatus::Published,
            SeoLandingPage::query()->where('slug', 'birthday-gifts-for-husband')->first()?->status,
        );
        $this->assertSame(count($approvedSlugs) + 1 + 5, SeoLandingPage::query()->count());
        $this->assertSame(0, SeoLandingPage::query()->whereNull('deleted_at')->where('slug', 'wedding-gifts-for-newlyweds')->count());
        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'birthday-gifts-for-husband-who-loves-coffee')->count());
    }

    public function test_return_gift_landing_pages_are_seeded_as_drafts_with_honest_filters(): void
    {
        $this->seed(DatabaseSeeder::class);

        $returnGifts = GiftType::query()->where('slug', 'return-gifts')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $under500 = BudgetRange::query()->where('slug', 'under-500')->firstOrFail();

        $expected = [
            'birthday-return-gifts' => ['occasion_id' => $birthday->id, 'budget_range_id' => null],
            'wedding-return-gifts' => ['occasion_id' => Occasion::query()->where('slug', 'wedding')->value('id'), 'budget_range_id' => null],
            'baby-shower-return-gifts' => ['occasion_id' => Occasion::query()->where('slug', 'baby-shower')->value('id'), 'budget_range_id' => null],
            'engagement-return-gifts' => ['occasion_id' => Occasion::query()->where('slug', 'engagement')->value('id'), 'budget_range_id' => null],
            'return-gifts-under-500' => ['occasion_id' => null, 'budget_range_id' => $under500->id],
        ];

        foreach ($expected as $slug => $filters) {
            $page = SeoLandingPage::query()->where('slug', $slug)->first();

            $this->assertNotNull($page, $slug);
            $this->assertSame(SeoLandingPageStatus::Draft, $page->status);
            $this->assertFalse($page->is_indexable);
            $this->assertFalse($page->include_in_sitemap);
            $this->assertSame($returnGifts->id, $page->gift_type_id);
            $this->assertSame($filters['occasion_id'], $page->occasion_id);
            $this->assertSame($filters['budget_range_id'], $page->budget_range_id);
            $this->assertNull($page->relationship_id);
            $this->assertNull($page->category_id);
            $this->assertNotSame('', (string) $page->intro_content);
        }

        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'corporate-return-gifts')->count());
        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'bulk-return-gifts')->count());
        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'office-party-favours')->count());
        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'return-gifts-under-100')->count());
        $this->assertSame(0, SeoLandingPage::query()->where('slug', 'return-gifts-under-250')->count());
        $this->assertSame(1, SeoLandingPage::query()->where('status', SeoLandingPageStatus::Published)->count());
    }

    public function test_seeded_filter_ids_match_taxonomy_and_are_unique_composites(): void
    {
        $this->seed(DatabaseSeeder::class);

        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $wife = Relationship::query()->where('slug', 'wife')->firstOrFail();
        $father = Relationship::query()->where('slug', 'father')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $anniversary = Occasion::query()->where('slug', 'anniversary')->firstOrFail();
        $coffee = Interest::query()->where('slug', 'coffee')->firstOrFail();

        $anniversaryHusband = SeoLandingPage::query()->where('slug', 'anniversary-gifts-for-husband')->firstOrFail();
        $this->assertSame($husband->id, $anniversaryHusband->relationship_id);
        $this->assertSame($anniversary->id, $anniversaryHusband->occasion_id);
        $this->assertNull($anniversaryHusband->recipient_type_id);
        $this->assertSame(0, $anniversaryHusband->interests()->count());

        $birthdayDad = SeoLandingPage::query()->where('slug', 'birthday-gifts-for-dad')->firstOrFail();
        $this->assertSame($father->id, $birthdayDad->relationship_id);
        $this->assertSame($birthday->id, $birthdayDad->occasion_id);

        $coffeeHusband = SeoLandingPage::query()->where('slug', 'gifts-for-husband-who-loves-coffee')->firstOrFail();
        $this->assertSame($husband->id, $coffeeHusband->relationship_id);
        $this->assertNull($coffeeHusband->occasion_id);
        $this->assertEqualsCanonicalizing([$coffee->id], $coffeeHusband->interests()->pluck('interests.id')->all());

        $birthdayWife = SeoLandingPage::query()->where('slug', 'birthday-gifts-for-wife')->firstOrFail();
        $this->assertSame($wife->id, $birthdayWife->relationship_id);
        $this->assertSame($birthday->id, $birthdayWife->occasion_id);

        $signatures = SeoLandingPage::query()->with('interests')->get()
            ->map(fn (SeoLandingPage $page): string => SeoLandingPageCandidateCatalog::signatureKey(
                SeoLandingPageEditorial::productFilters($page),
            ));

        $this->assertSame($signatures->count(), $signatures->unique()->count());
    }
}
