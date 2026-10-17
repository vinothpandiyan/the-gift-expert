<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\Models\Relationship;
use Database\Seeders\CategorySeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\RecipientGenderSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Database\Seeders\TaxonomyApplicabilityRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;

/**
 * Real taxonomy seeders plus the real applicability rules, so eligibility tests
 * exercise the same Husband/Wife/baby-shower/return-gift semantics as production.
 */
trait SeedsRecipientEligibilityCatalog
{
    use RefreshDatabase;

    private int $eligibilityProductSequence = 0;

    protected function seedEligibilityTaxonomy(): void
    {
        $this->seed([
            RelationshipSeeder::class,
            OccasionSeeder::class,
            RecipientGenderSeeder::class,
            RecipientTypeSeeder::class,
            CategorySeeder::class,
            GiftTypeSeeder::class,
            InterestSeeder::class,
            TaxonomyApplicabilityRuleSeeder::class,
        ]);
    }

    /**
     * @param  array{
     *     relationships?: list<string>,
     *     occasions?: list<string>,
     *     interests?: list<string>,
     *     genders?: list<string>,
     *     recipient_types?: list<string>,
     *     categories?: list<string>,
     *     gift_types?: list<string>,
     * }  $tags
     * @param  array<string, mixed>  $attributes
     */
    protected function eligibilityProduct(string $name, array $tags = [], array $attributes = []): Product
    {
        $this->eligibilityProductSequence++;

        $product = GiftCatalogTestHelpers::publishedGift(array_merge([
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.$this->eligibilityProductSequence)->toString(),
            'price_amount' => (string) (500 + $this->eligibilityProductSequence),
            'price_currency' => 'INR',
            'published_at' => now()->subMinutes(1000 - $this->eligibilityProductSequence),
        ], $attributes));

        $product->relationships()->sync($this->idsBySlug(Relationship::class, $tags['relationships'] ?? []));
        $product->occasions()->sync($this->idsBySlug(Occasion::class, $tags['occasions'] ?? []));
        $product->interests()->sync($this->idsBySlug(Interest::class, $tags['interests'] ?? []));
        $product->recipientGenders()->sync($this->idsBySlug(RecipientGender::class, $tags['genders'] ?? []));
        $product->recipientTypes()->sync($this->idsBySlug(RecipientType::class, $tags['recipient_types'] ?? []));
        $product->giftTypes()->sync($this->idsBySlug(GiftType::class, $tags['gift_types'] ?? []));

        $categories = [];

        foreach (array_values($tags['categories'] ?? []) as $index => $slug) {
            $categories[(int) Category::query()->where('slug', $slug)->value('id')] = ['is_primary' => $index === 0];
        }

        $product->categories()->sync($categories);

        return $product->fresh();
    }

    protected function relationshipId(string $slug): int
    {
        return (int) Relationship::query()->where('slug', $slug)->value('id');
    }

    /**
     * @param  class-string  $model
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function idsBySlug(string $model, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $ids = $model::query()->whereIn('slug', $slugs)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertCount(count($slugs), $ids, 'Unknown taxonomy slug in test fixture: '.implode(',', $slugs));

        return $ids;
    }
}
