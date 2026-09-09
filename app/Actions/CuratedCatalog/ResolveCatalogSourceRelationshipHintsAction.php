<?php

namespace App\Actions\CuratedCatalog;

use App\Enums\CatalogSourceListKind;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\Product;

class ResolveCatalogSourceRelationshipHintsAction
{
    /**
     * @return list<int>
     */
    public function execute(AffiliateLink|Product $subject): array
    {
        if ($subject instanceof Product && $subject->relationLoaded('affiliateLinks')) {
            return $this->fromLoadedProduct($subject);
        }

        $linkIds = $subject instanceof AffiliateLink
            ? [$subject->id]
            : $subject->affiliateLinks()->pluck('id')->all();

        if ($linkIds === []) {
            return [];
        }

        return CatalogProductSource::query()
            ->whereIn('affiliate_link_id', $linkIds)
            ->whereHas('sourceList', function ($query): void {
                $query->where('is_active', true)
                    ->where('kind', CatalogSourceListKind::RecipientHint)
                    ->whereNotNull('relationship_id');
            })
            ->with('sourceList:id,relationship_id,kind,is_active')
            ->get()
            ->pluck('sourceList.relationship_id')
            ->filter(fn (mixed $id): bool => is_int($id) || is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function fromLoadedProduct(Product $product): array
    {
        $ids = [];

        foreach ($product->affiliateLinks as $link) {
            $sources = $link->relationLoaded('catalogProductSources')
                ? $link->catalogProductSources
                : $link->catalogProductSources()->with('sourceList')->get();

            foreach ($sources as $source) {
                $list = $source->sourceList;

                if (
                    $list !== null
                    && $list->is_active
                    && $list->kind === CatalogSourceListKind::RecipientHint
                    && $list->relationship_id !== null
                ) {
                    $ids[] = (int) $list->relationship_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
