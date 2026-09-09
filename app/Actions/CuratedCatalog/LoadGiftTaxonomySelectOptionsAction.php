<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;

class LoadGiftTaxonomySelectOptionsAction
{
    public function __construct(
        private IsAcceptableMerchandisingCategoryAction $isAcceptableMerchandisingCategory,
    ) {}

    /**
     * @return array{
     *     categories: array<int, string>,
     *     relationships: array<int, string>,
     *     recipient_types: array<int, string>,
     *     occasions: array<int, string>,
     *     interests: array<int, string>,
     *     professions: array<int, string>,
     *     gift_types: array<int, string>
     * }
     */
    public function execute(): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'relationships' => $this->dimensionOptions(Relationship::class),
            'recipient_types' => $this->dimensionOptions(RecipientType::class),
            'occasions' => $this->dimensionOptions(Occasion::class),
            'interests' => $this->dimensionOptions(Interest::class),
            'professions' => $this->dimensionOptions(Profession::class),
            'gift_types' => $this->dimensionOptions(GiftType::class),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('full_path')
            ->orderBy('name')
            ->get(['id', 'name', 'full_path'])
            ->filter(fn (Category $category): bool => $this->isAcceptableMerchandisingCategory->execute((int) $category->id))
            ->mapWithKeys(fn (Category $category): array => [
                (int) $category->id => filled($category->full_path) ? (string) $category->full_path : (string) $category->name,
            ])
            ->all();
    }

    /**
     * @param  class-string  $model
     * @return array<int, string>
     */
    private function dimensionOptions(string $model): array
    {
        return $model::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function optionsFor(TaxonomyDimension $dimension): array
    {
        $all = $this->execute();

        return match ($dimension) {
            TaxonomyDimension::Category => $all['categories'],
            TaxonomyDimension::Relationship => $all['relationships'],
            TaxonomyDimension::RecipientType => $all['recipient_types'],
            TaxonomyDimension::Occasion => $all['occasions'],
            TaxonomyDimension::Interest => $all['interests'],
            TaxonomyDimension::Profession => $all['professions'],
            TaxonomyDimension::GiftType => $all['gift_types'],
        };
    }
}
