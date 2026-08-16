<?php

namespace Tests\Feature\SeoLandingPage;

use App\Enums\SeoLandingPageStatus;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLandingPageSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_can_be_created_with_relationship_and_occasion(): void
    {
        $relationship = $this->relationship('Husband', 'husband');
        $occasion = $this->occasion('Birthday', 'birthday');

        $page = SeoLandingPage::factory()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ]);

        $this->assertTrue($page->relationship->is($relationship));
        $this->assertTrue($page->occasion->is($occasion));
        $this->assertNull($page->recipient_type_id);
        $this->assertSame('relationships', $page->relationship()->getRelated()->getTable());
        $this->assertSame('occasions', $page->occasion()->getRelated()->getTable());
    }

    public function test_relationship_and_recipient_type_point_to_distinct_tables(): void
    {
        $relationship = $this->relationship('Husband', 'husband');
        $recipientType = $this->recipientType('Adult', 'adult');

        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $relationship->id,
            'recipient_type_id' => $recipientType->id,
        ]);

        $this->assertSame('relationships', $page->relationship()->getRelated()->getTable());
        $this->assertSame('recipient_types', $page->recipientType()->getRelated()->getTable());
        $this->assertSame('Husband', $page->relationship->name);
        $this->assertSame('Adult', $page->recipientType->name);
        $this->assertNotSame($page->relationship_id, $page->recipient_type_id);
    }

    public function test_landing_page_can_have_only_relationship(): void
    {
        $relationship = $this->relationship('Husband', 'husband');

        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $relationship->id,
        ]);

        $this->assertTrue($page->relationship->is($relationship));
        $this->assertNull($page->occasion_id);
        $this->assertNull($page->recipient_type_id);
    }

    public function test_landing_page_can_have_only_recipient_type(): void
    {
        $recipientType = $this->recipientType('Kids', 'kids');

        $page = SeoLandingPage::factory()->create([
            'recipient_type_id' => $recipientType->id,
        ]);

        $this->assertTrue($page->recipientType->is($recipientType));
        $this->assertNull($page->relationship_id);
    }

    public function test_landing_page_can_have_only_occasion(): void
    {
        $occasion = $this->occasion('Birthday', 'birthday');

        $page = SeoLandingPage::factory()->create([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertTrue($page->occasion->is($occasion));
        $this->assertNull($page->relationship_id);
    }

    public function test_landing_page_can_have_only_interest(): void
    {
        $interest = $this->interest('Coffee', 'coffee');

        $page = SeoLandingPage::factory()->create();
        $page->interests()->attach($interest);

        $this->assertNull($page->relationship_id);
        $this->assertTrue($page->interests()->whereKey($interest->id)->exists());
    }

    public function test_landing_page_can_have_relationship_occasion_and_budget(): void
    {
        $relationship = $this->relationship('Husband', 'husband');
        $occasion = $this->occasion('Birthday', 'birthday');
        $budget = BudgetRange::query()->create([
            'name' => '₹500–₹1,000',
            'slug' => '500-1000',
            'min_amount' => '500.00',
            'max_amount' => '1000.00',
            'currency' => 'INR',
        ]);

        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
        ]);

        $this->assertTrue($page->budgetRange->is($budget));
        $this->assertSame('budget_ranges', $page->budgetRange()->getRelated()->getTable());
    }

    public function test_landing_page_can_have_multiple_dimensions(): void
    {
        $page = SeoLandingPage::factory()->create([
            'occasion_id' => $this->occasion('Birthday', 'birthday')->id,
            'relationship_id' => $this->relationship('Husband', 'husband')->id,
            'recipient_type_id' => $this->recipientType('Adult', 'adult')->id,
            'profession_id' => Profession::query()->create([
                'name' => 'Engineer',
                'slug' => 'engineer',
            ])->id,
            'gift_type_id' => GiftType::query()->create([
                'name' => 'Gift Cards',
                'slug' => 'gift-cards',
            ])->id,
            'budget_range_id' => BudgetRange::query()->create([
                'name' => 'Under ₹500',
                'slug' => 'under-500',
                'max_amount' => '499.99',
                'currency' => 'INR',
            ])->id,
        ]);

        $interest = $this->interest('Coffee', 'coffee');
        $page->interests()->attach($interest);

        $page->refresh();

        $this->assertNotNull($page->occasion_id);
        $this->assertNotNull($page->relationship_id);
        $this->assertNotNull($page->recipient_type_id);
        $this->assertNotNull($page->profession_id);
        $this->assertNotNull($page->gift_type_id);
        $this->assertNotNull($page->budget_range_id);
        $this->assertTrue($page->interests->contains($interest));
    }

    public function test_interests_can_be_attached_and_retrieved(): void
    {
        $page = SeoLandingPage::factory()->create();
        $coffee = $this->interest('Coffee', 'coffee');
        $fitness = $this->interest('Fitness', 'fitness');

        $page->interests()->attach([$coffee->id, $fitness->id]);

        $this->assertEqualsCanonicalizing(
            [$coffee->id, $fitness->id],
            $page->interests()->pluck('interests.id')->all(),
        );
        $this->assertTrue($coffee->seoLandingPages()->whereKey($page->id)->exists());
    }

    public function test_duplicate_interest_attachment_is_prevented(): void
    {
        $page = SeoLandingPage::factory()->create();
        $interest = $this->interest('Coffee', 'coffee');

        $page->interests()->attach($interest);

        $this->expectException(QueryException::class);

        $page->interests()->attach($interest);
    }

    public function test_invalid_taxonomy_references_cannot_be_persisted(): void
    {
        $this->expectException(QueryException::class);

        SeoLandingPage::factory()->create([
            'relationship_id' => 999999,
        ]);
    }

    public function test_referenced_taxonomy_cannot_be_force_deleted(): void
    {
        $relationship = $this->relationship('Husband', 'husband');

        SeoLandingPage::factory()->create([
            'relationship_id' => $relationship->id,
        ]);

        $this->expectException(QueryException::class);

        $relationship->forceDelete();
    }

    public function test_deleting_a_landing_page_removes_interest_pivot_rows(): void
    {
        $page = SeoLandingPage::factory()->create();
        $interest = $this->interest('Coffee', 'coffee');
        $page->interests()->attach($interest);

        $this->assertDatabaseHas('seo_landing_page_interests', [
            'seo_landing_page_id' => $page->id,
            'interest_id' => $interest->id,
        ]);

        $page->forceDelete();

        $this->assertDatabaseMissing('seo_landing_page_interests', [
            'seo_landing_page_id' => $page->id,
            'interest_id' => $interest->id,
        ]);
        $this->assertDatabaseHas('interests', [
            'id' => $interest->id,
        ]);
    }

    public function test_referenced_interest_cannot_be_force_deleted(): void
    {
        $page = SeoLandingPage::factory()->create();
        $interest = $this->interest('Coffee', 'coffee');
        $page->interests()->attach($interest);

        $this->expectException(QueryException::class);

        $interest->forceDelete();
    }

    public function test_duplicate_landing_page_slugs_are_rejected(): void
    {
        SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
        ]);

        $this->expectException(QueryException::class);

        SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
        ]);
    }

    public function test_soft_deleted_landing_pages_are_excluded_from_default_query(): void
    {
        $page = SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
            'status' => SeoLandingPageStatus::Draft,
        ]);

        $page->delete();

        $this->assertSoftDeleted('seo_landing_pages', [
            'id' => $page->id,
        ]);
        $this->assertNull(SeoLandingPage::query()->find($page->id));
        $this->assertTrue(SeoLandingPage::withTrashed()->whereKey($page->id)->exists());
    }

    public function test_redirect_from_slug_is_unique(): void
    {
        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-slug',
            'to_slug' => 'new-slug',
        ]);

        $this->expectException(QueryException::class);

        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-slug',
            'to_slug' => 'another-slug',
        ]);
    }

    public function test_redirect_landing_page_relationship_is_optional(): void
    {
        $redirect = SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-slug',
            'to_slug' => 'new-slug',
        ]);

        $this->assertNull($redirect->seo_landing_page_id);
        $this->assertNull($redirect->seoLandingPage);
    }

    public function test_deleting_a_landing_page_nulls_redirect_foreign_key(): void
    {
        $page = SeoLandingPage::factory()->create([
            'slug' => 'new-slug',
        ]);

        $redirect = SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-slug',
            'to_slug' => 'new-slug',
            'seo_landing_page_id' => $page->id,
        ]);

        $page->forceDelete();

        $redirect->refresh();

        $this->assertNull($redirect->seo_landing_page_id);
        $this->assertDatabaseHas('seo_landing_page_redirects', [
            'id' => $redirect->id,
            'from_slug' => 'old-slug',
            'to_slug' => 'new-slug',
            'seo_landing_page_id' => null,
        ]);
    }

    private function relationship(string $name, string $slug): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function recipientType(string $name, string $slug): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function occasion(string $name, string $slug): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function interest(string $name, string $slug): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
