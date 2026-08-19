<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;

class LoadActiveTaxonomyCatalogAction
{
    public function execute(): CommercialTaxonomyCatalog
    {
        return new CommercialTaxonomyCatalog(
            categories: Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'slug', 'parent_id', 'full_path'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'parent_id' => $category->parent_id,
                    'full_path' => $category->full_path,
                ])
                ->all(),
            occasions: $this->dimension(Occasion::class),
            relationships: $this->dimension(Relationship::class),
            recipientTypes: $this->dimension(RecipientType::class),
            interests: $this->dimension(Interest::class),
            professions: $this->dimension(Profession::class),
            giftTypes: $this->dimension(GiftType::class),
        );
    }

    /**
     * @param  class-string  $model
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function dimension(string $model): array
    {
        return $model::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
            ])
            ->all();
    }
}
