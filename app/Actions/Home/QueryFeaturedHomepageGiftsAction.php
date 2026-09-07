<?php

namespace App\Actions\Home;

use App\Enums\AffiliateLinkStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QueryFeaturedHomepageGiftsAction
{
    public const LIMIT = 8;

    /**
     * @return Collection<int, Product>
     */
    public function execute(int $limit = self::LIMIT): Collection
    {
        $limit = max(1, $limit);

        return Product::query()
            ->published()
            ->where('is_featured', true)
            ->whereHas('affiliateLinks', function (Builder $query): void {
                $query->where('status', AffiliateLinkStatus::Active);
            })
            ->with($this->presentationRelations())
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function presentationRelations(): array
    {
        return [
            'categories',
            'images' => fn ($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id'),
            'affiliateLinks' => fn ($query) => $query
                ->active()
                ->with('merchant')
                ->orderByDesc('is_primary')
                ->orderBy('id'),
        ];
    }
}
