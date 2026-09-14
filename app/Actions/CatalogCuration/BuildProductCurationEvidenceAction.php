<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class BuildProductCurationEvidenceAction
{
    public function execute(Product $product): ProductCurationEvidence
    {
        $product->loadMissing([
            'categories:id,name,slug',
            'relationships:id,name,slug',
            'occasions:id,name,slug',
            'interests:id,name,slug',
            'giftTypes:id,name,slug',
            'recipientTypes:id,name,slug',
            'recipientGenders:id,name,slug',
            'professions:id,name,slug',
            'affiliateLinks.merchant:id,name,slug',
            'affiliateLinks.catalogProductSources.sourceList:id,name,normalized_name,kind',
            'images',
        ]);

        $taxonomy = [];

        foreach ([
            'categories',
            'relationships',
            'occasions',
            'interests',
            'gift_types' => 'giftTypes',
            'recipient_types' => 'recipientTypes',
            'recipient_genders' => 'recipientGenders',
            'professions',
        ] as $key => $relation) {
            $name = is_int($key) ? $relation : $key;
            /** @var Collection<int, Model> $records */
            $records = $product->getRelation($relation);
            $taxonomy[$name] = $records
                ->map(fn ($record): array => array_filter([
                    'id' => (int) $record->getKey(),
                    'name' => (string) $record->name,
                    'slug' => (string) $record->slug,
                    'is_primary' => $name === 'categories' ? (bool) $record->pivot?->is_primary : null,
                ], fn (mixed $value): bool => $value !== null))
                ->sortBy('id')
                ->values()
                ->all();
        }

        $offers = $product->affiliateLinks
            ->map(fn ($link): array => [
                'merchant' => [
                    'name' => $link->merchant?->name,
                    'slug' => $link->merchant?->slug,
                ],
                'external_product_id' => $link->external_product_id,
                'is_primary' => (bool) $link->is_primary,
                'status' => $link->status?->value ?? (string) $link->status,
                'availability' => $link->availability,
                'last_verified_at' => $link->last_verified_at?->toIso8601String(),
                'last_seen_at' => $link->last_seen_at?->toIso8601String(),
            ])
            ->sortBy(fn (array $offer): string => ($offer['merchant']['slug'] ?? '').'|'.($offer['external_product_id'] ?? ''))
            ->values()
            ->all();

        $images = $product->images
            ->map(fn ($image): array => [
                'is_primary' => (bool) $image->is_primary,
                'sort_order' => (int) $image->sort_order,
                'has_local_asset' => filled($image->path),
                'has_source' => filled($image->source_url),
                'acquired_at' => $image->acquired_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $provenance = $product->affiliateLinks
            ->flatMap(fn ($link) => $link->catalogProductSources->map(fn ($source): array => [
                'merchant_slug' => $link->merchant?->slug,
                'list_name' => $source->sourceList?->name,
                'list_kind' => $source->sourceList?->kind?->value,
                'first_seen_at' => $source->first_seen_at?->toIso8601String(),
                'last_seen_at' => $source->last_seen_at?->toIso8601String(),
                'occurrence_count' => (int) $source->occurrence_count,
            ]))
            ->sortBy(fn (array $source): string => ($source['merchant_slug'] ?? '').'|'.($source['list_name'] ?? ''))
            ->values()
            ->all();

        return new ProductCurationEvidence(
            productId: (int) $product->id,
            name: (string) $product->name,
            shortDescription: $product->short_description,
            description: $product->description,
            brand: $product->brand,
            status: $product->status->value,
            priceAmount: $product->price_amount,
            priceCurrency: (string) $product->price_currency,
            compareAtAmount: $product->compare_at_amount,
            rating: null,
            reviewCount: null,
            taxonomy: $taxonomy,
            offers: $offers,
            images: $images,
            provenance: $provenance,
        );
    }
}
