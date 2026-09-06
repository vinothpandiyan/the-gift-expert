<?php

namespace Tests\Unit\Actions;

use App\Actions\Discovery\QueryRelatedGiftsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class QueryRelatedGiftsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_published_same_category_gifts_and_excludes_the_source(): void
    {
        $category = $this->category('Travel Accessories');
        $source = GiftCatalogTestHelpers::publishedGift(['name' => 'Source Gift', 'slug' => 'source-gift']);
        $related = GiftCatalogTestHelpers::publishedGift(['name' => 'Related Gift', 'slug' => 'related-gift']);
        $other = GiftCatalogTestHelpers::publishedGift(['name' => 'Other Gift', 'slug' => 'other-gift']);

        $source->categories()->attach($category->id, ['is_primary' => true]);
        $related->categories()->attach($category->id, ['is_primary' => true]);
        $other->categories()->attach($this->category('Lighting')->id, ['is_primary' => true]);

        $results = app(QueryRelatedGiftsAction::class)->execute($source->fresh(['categories']));

        $this->assertSame([$related->id], $results->pluck('id')->all());
    }

    public function test_it_falls_back_to_relationship_when_no_primary_category_exists(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $source = GiftCatalogTestHelpers::publishedGift(['name' => 'Source Wallet', 'slug' => 'source-wallet']);
        $related = GiftCatalogTestHelpers::publishedGift(['name' => 'Related Watch', 'slug' => 'related-watch']);

        $source->relationships()->attach($husband);
        $related->relationships()->attach($husband);

        $results = app(QueryRelatedGiftsAction::class)->execute($source->fresh(['categories', 'relationships']));

        $this->assertSame([$related->id], $results->pluck('id')->all());
    }

    public function test_it_omits_drafts_inactive_links_and_returns_empty_without_taxonomy(): void
    {
        $category = $this->category('Desk Accessories');
        $source = GiftCatalogTestHelpers::publishedGift(['name' => 'Source Desk', 'slug' => 'source-desk']);
        $draft = Product::factory()->draft()->create(['name' => 'Draft Desk', 'slug' => 'draft-desk']);
        $inactive = GiftCatalogTestHelpers::publishedGift(['name' => 'Inactive Desk', 'slug' => 'inactive-desk']);
        $untagged = GiftCatalogTestHelpers::publishedGift(['name' => 'Untagged Desk', 'slug' => 'untagged-desk']);

        $source->categories()->attach($category->id, ['is_primary' => true]);
        $draft->categories()->attach($category->id, ['is_primary' => true]);
        $inactive->categories()->attach($category->id, ['is_primary' => true]);
        $inactive->affiliateLinks()->update(['status' => AffiliateLinkStatus::Inactive]);

        $this->assertTrue(
            app(QueryRelatedGiftsAction::class)
                ->execute($source->fresh(['categories']))
                ->isEmpty(),
        );
        $this->assertTrue(
            app(QueryRelatedGiftsAction::class)
                ->execute($untagged->fresh(['categories', 'relationships', 'occasions', 'interests', 'giftTypes']))
                ->isEmpty(),
        );
        $this->assertNotSame(ProductStatus::Published, $draft->status);
    }

    public function test_it_caps_results_at_four(): void
    {
        $category = $this->category('Capped Category');
        $source = GiftCatalogTestHelpers::publishedGift(['name' => 'Cap Source', 'slug' => 'cap-source']);
        $source->categories()->attach($category->id, ['is_primary' => true]);

        foreach (range(1, 6) as $index) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "Cap Related {$index}",
                'slug' => "cap-related-{$index}",
            ]);
            $gift->categories()->attach($category->id, ['is_primary' => true]);
        }

        $results = app(QueryRelatedGiftsAction::class)->execute($source->fresh(['categories']));

        $this->assertCount(4, $results);
        $this->assertTrue($results->every(fn (Product $product) => $product->relationLoaded('images')));
        $this->assertTrue($results->every(fn (Product $product) => $product->relationLoaded('affiliateLinks')));
    }

    private function category(string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }
}
