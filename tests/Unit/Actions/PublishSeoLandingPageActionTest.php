<?php

namespace Tests\Unit\Actions;

use App\Actions\SeoLandingPage\PublishSeoLandingPageAction;
use App\Enums\SeoLandingPageStatus;
use App\Models\BudgetRange;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublishSeoLandingPageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_with_relationship_only(): void
    {
        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $this->relationship()->id,
            'is_indexable' => false,
            'include_in_sitemap' => false,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $page->refresh();

        $this->assertSame(SeoLandingPageStatus::Published, $page->status);
        $this->assertNotNull($page->published_at);
        $this->assertFalse($page->is_indexable);
        $this->assertFalse($page->include_in_sitemap);
    }

    public function test_it_publishes_with_occasion_only(): void
    {
        $page = SeoLandingPage::factory()->create([
            'occasion_id' => $this->occasion()->id,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $this->assertSame(SeoLandingPageStatus::Published, $page->fresh()->status);
    }

    public function test_it_publishes_with_interest_only(): void
    {
        $page = SeoLandingPage::factory()->create();
        $page->interests()->attach($this->interest('Coffee', 'coffee')->id);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $this->assertSame(SeoLandingPageStatus::Published, $page->fresh()->status);
    }

    public function test_it_publishes_with_relationship_and_occasion_without_recipient_type(): void
    {
        $page = SeoLandingPage::factory()->create([
            'name' => 'Birthday Gifts for Husband',
            'relationship_id' => $this->relationship()->id,
            'occasion_id' => $this->occasion()->id,
            'recipient_type_id' => null,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $page->refresh();

        $this->assertSame(SeoLandingPageStatus::Published, $page->status);
        $this->assertNull($page->recipient_type_id);
        $this->assertSame('Husband', $page->relationship->name);
        $this->assertSame('Birthday', $page->occasion->name);
    }

    public function test_it_publishes_with_budget_only(): void
    {
        $page = SeoLandingPage::factory()->create([
            'budget_range_id' => BudgetRange::query()->create([
                'name' => 'Under ₹500',
                'slug' => 'under-500',
                'max_amount' => '499.99',
                'currency' => 'INR',
            ])->id,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $this->assertSame(SeoLandingPageStatus::Published, $page->fresh()->status);
    }

    public function test_it_fails_when_no_filter_dimension_exists(): void
    {
        $page = SeoLandingPage::factory()->create();

        try {
            app(PublishSeoLandingPageAction::class)->execute($page);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Add at least one filter dimension before publishing.',
                $exception->errors()['status'],
            );
        }

        $this->assertSame(SeoLandingPageStatus::Draft, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_at);
    }

    public function test_it_fails_when_the_page_is_soft_deleted(): void
    {
        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $this->relationship()->id,
        ]);
        $page->delete();

        try {
            app(PublishSeoLandingPageAction::class)->execute($page);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'A deleted SEO landing page cannot be published.',
                $exception->errors()['status'],
            );
        }
    }

    public function test_relationship_and_recipient_type_remain_independent(): void
    {
        $relationship = $this->relationship();
        $recipientType = RecipientType::query()->create([
            'name' => 'Adult',
            'slug' => 'adult',
        ]);

        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $relationship->id,
            'recipient_type_id' => $recipientType->id,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $page->refresh();

        $this->assertSame('Husband', $page->relationship->name);
        $this->assertSame('Adult', $page->recipientType->name);
        $this->assertSame(SeoLandingPageStatus::Published, $page->status);
    }

    public function test_it_does_not_flip_indexability_or_sitemap_flags(): void
    {
        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $this->relationship()->id,
            'is_indexable' => true,
            'include_in_sitemap' => true,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $page->refresh();

        $this->assertTrue($page->is_indexable);
        $this->assertTrue($page->include_in_sitemap);
    }

    public function test_it_does_not_create_slug_redirects(): void
    {
        $page = SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
            'relationship_id' => $this->relationship()->id,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $this->assertSame(0, SeoLandingPageRedirect::query()->count());
        $this->assertSame('birthday-gifts-for-husband', $page->fresh()->slug);
    }

    public function test_it_preserves_original_published_at_on_republish(): void
    {
        $publishedAt = now()->subDay();
        $page = SeoLandingPage::factory()->create([
            'status' => SeoLandingPageStatus::Draft,
            'published_at' => $publishedAt,
            'relationship_id' => $this->relationship()->id,
        ]);

        app(PublishSeoLandingPageAction::class)->execute($page);

        $this->assertSame(
            $publishedAt->toDateTimeString(),
            $page->fresh()->published_at->toDateTimeString(),
        );
    }

    private function relationship(): Relationship
    {
        return Relationship::query()->firstOrCreate(
            ['slug' => 'husband'],
            ['name' => 'Husband'],
        );
    }

    private function occasion(): Occasion
    {
        return Occasion::query()->firstOrCreate(
            ['slug' => 'birthday'],
            ['name' => 'Birthday'],
        );
    }

    private function interest(string $name, string $slug): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
