<?php

namespace Tests\Support;

use App\Actions\CuratedCatalog\BuildCuratedRelationshipHintFingerprintAction;
use App\Actions\CuratedCatalog\BuildCuratedTaxonomyContentFingerprintAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Category;
use App\Models\Product;

trait BuildsClassificationReviewFixtures
{
    private function merchandisingCategory(string $name, string $slug, ?int $parentId = null): Category
    {
        return Category::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function taxonomyValue(string $model, string $name): mixed
    {
        return $model::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $proposalOverrides
     */
    private function reviewProduct(
        Category $primary,
        array $proposalOverrides = [],
        TaxonomyClassificationStatus $status = TaxonomyClassificationStatus::Review,
    ): Product {
        $proposal = array_merge([
            'primary_category_id' => $primary->id,
            'category_ids' => [$primary->id],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'occasion_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
            'confidence' => [
                'primary_category' => 0.70,
                'gift_types' => 0.90,
            ],
            'reasoning_summary' => [
                'primary_category' => 'Matches the merchandising family.',
            ],
            'warnings' => [],
            'review_reasons' => ['low_primary_category_confidence'],
            'classification_version' => (int) config('curated_catalog.taxonomy_classification.version', 1),
            'source_title' => 'Review Gift',
        ], $proposalOverrides);

        $product = Product::factory()->create([
            'name' => 'Review Gift',
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => $status,
            'taxonomy_classification_version' => (int) config('curated_catalog.taxonomy_classification.version', 1),
            'taxonomy_review_reasons' => $proposal['review_reasons'],
            'taxonomy_classification_proposal' => $proposal,
            'price_amount' => '1299.00',
        ]);

        $product->taxonomy_content_fingerprint = app(BuildCuratedTaxonomyContentFingerprintAction::class)->execute($product);
        $product->taxonomy_relationship_hint_fingerprint = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product);
        $product->save();

        return $product->fresh();
    }
}
