<?php

namespace Tests\Feature\Filament;

use App\Enums\SeoLandingPageStatus;
use App\Filament\Resources\SeoLandingPages\Pages\CreateSeoLandingPage;
use App\Filament\Resources\SeoLandingPages\Pages\EditSeoLandingPage;
use App\Filament\Resources\SeoLandingPages\Pages\ListSeoLandingPages;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeoLandingPageResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_loads(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSeoLandingPages::class)
            ->assertOk();
    }

    public function test_it_can_create_an_seo_landing_page_as_draft(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Birthday Gifts for Husband',
                'slug' => 'birthday-gifts-for-husband',
                'heading' => 'Birthday Gifts for Husband',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('seo_landing_pages', [
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'status' => SeoLandingPageStatus::Draft->value,
            'published_at' => null,
        ]);
    }

    public function test_it_can_edit_seo_and_content_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'heading' => 'Old Heading',
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Coffee Gifts',
                'slug' => 'coffee-gifts',
                'heading' => 'Coffee Gifts',
                'meta_title' => 'Coffee Gift Ideas',
                'meta_description' => 'Find coffee gifts.',
                'canonical_url' => 'https://cdn.example.test/coffee-gifts',
                'is_indexable' => true,
                'include_in_sitemap' => false,
                'intro_content' => 'Intro copy',
                'body_content' => 'Body copy',
                'faq_content' => 'FAQ copy',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertSame('Coffee Gifts', $page->name);
        $this->assertSame('Coffee Gift Ideas', $page->meta_title);
        $this->assertSame('Find coffee gifts.', $page->meta_description);
        $this->assertSame('https://cdn.example.test/coffee-gifts', $page->canonical_url);
        $this->assertTrue($page->is_indexable);
        $this->assertFalse($page->include_in_sitemap);
        $this->assertSame('Intro copy', $page->intro_content);
        $this->assertSame('Body copy', $page->body_content);
        $this->assertSame('FAQ copy', $page->faq_content);
        $this->assertSame(SeoLandingPageStatus::Draft, $page->status);
    }

    public function test_it_can_save_husband_relationship_independently_of_recipient_type(): void
    {
        $this->actingAs(User::factory()->create());

        $husband = $this->relationship();
        $adult = RecipientType::query()->create([
            'name' => 'Adult',
            'slug' => 'adult',
        ]);
        $birthday = $this->occasion();

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Birthday Gifts for Husband',
                'slug' => 'birthday-gifts-for-husband',
                'heading' => 'Birthday Gifts for Husband',
                'sort_order' => 0,
                'relationship_id' => $husband->id,
                'occasion_id' => $birthday->id,
                'recipient_type_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = SeoLandingPage::query()->where('slug', 'birthday-gifts-for-husband')->first();

        $this->assertNotNull($page);
        $this->assertSame($husband->id, $page->relationship_id);
        $this->assertSame($birthday->id, $page->occasion_id);
        $this->assertNull($page->recipient_type_id);
        $this->assertSame('relationships', $page->relationship()->getRelated()->getTable());
        $this->assertSame('recipient_types', $adult->getTable());
    }

    public function test_it_can_attach_multiple_interests(): void
    {
        $this->actingAs(User::factory()->create());

        $coffee = $this->interest('Coffee', 'coffee');
        $technology = $this->interest('Technology', 'technology');

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Coffee and Technology Gifts',
                'slug' => 'coffee-technology-gifts',
                'heading' => 'Coffee and Technology Gifts',
                'sort_order' => 0,
                'interests' => [$coffee->id, $technology->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = SeoLandingPage::query()->where('slug', 'coffee-technology-gifts')->first();

        $this->assertNotNull($page);
        $this->assertEqualsCanonicalizing(
            [$coffee->id, $technology->id],
            $page->interests()->pluck('interests.id')->all(),
        );
    }

    public function test_taxonomy_entity_slugs_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->relationship();

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Husband',
                'slug' => 'husband',
                'heading' => 'Husband',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_edit_page_shows_matching_published_gift_count(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->create([
            'relationship_id' => $this->relationship()->id,
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee('0 published gifts currently match these filters.');
    }

    public function test_slug_uniqueness_is_enforced(): void
    {
        $this->actingAs(User::factory()->create());

        SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
        ]);

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Duplicate',
                'slug' => 'birthday-gifts-for-husband',
                'heading' => 'Duplicate',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_reserved_exact_slugs_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['gifts', 'gifts-for', 'gift-ideas', 'occasions'] as $slug) {
            Livewire::test(CreateSeoLandingPage::class)
                ->fillForm([
                    'name' => 'Reserved '.$slug,
                    'slug' => $slug,
                    'heading' => 'Reserved',
                    'sort_order' => 0,
                ])
                ->call('create')
                ->assertHasFormErrors(['slug']);
        }
    }

    public function test_compound_reserved_prefix_slugs_are_allowed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Gifts for Husband',
                'slug' => 'gifts-for-husband',
                'heading' => 'Gifts for Husband',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('seo_landing_pages', [
            'slug' => 'gifts-for-husband',
            'status' => SeoLandingPageStatus::Draft->value,
        ]);
    }

    public function test_uppercase_and_slashed_slugs_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Invalid',
                'slug' => 'Gifts-For-Husband',
                'heading' => 'Invalid',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateSeoLandingPage::class)
            ->fillForm([
                'name' => 'Invalid Path',
                'slug' => 'gifts/husband',
                'heading' => 'Invalid Path',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_publish_action_blocks_when_no_filter_dimension_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->create([
            'status' => SeoLandingPageStatus::Draft,
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->callAction('publish');

        $this->assertSame(SeoLandingPageStatus::Draft, $page->fresh()->status);
    }

    public function test_publish_action_publishes_when_a_filter_dimension_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->create([
            'status' => SeoLandingPageStatus::Draft,
            'relationship_id' => $this->relationship()->id,
            'occasion_id' => $this->occasion()->id,
            'recipient_type_id' => null,
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->callAction('publish')
            ->assertHasNoActionErrors();

        $page->refresh();

        $this->assertSame(SeoLandingPageStatus::Published, $page->status);
        $this->assertNotNull($page->published_at);
        $this->assertNull($page->recipient_type_id);
    }

    public function test_unpublish_action_returns_the_page_to_draft(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->published()->create([
            'relationship_id' => $this->relationship()->id,
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->callAction('unpublish')
            ->assertHasNoActionErrors();

        $this->assertSame(SeoLandingPageStatus::Draft, $page->fresh()->status);
    }

    public function test_it_can_soft_delete_and_restore_a_landing_page(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->create();

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted('seo_landing_pages', [
            'id' => $page->id,
        ]);

        Livewire::test(EditSeoLandingPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->callAction('restore')
            ->assertHasNoActionErrors();

        $this->assertNotSoftDeleted('seo_landing_pages', [
            'id' => $page->id,
        ]);
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
