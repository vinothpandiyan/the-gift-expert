<?php

namespace App\Actions\LaunchPublication;

use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class QueryLaunchPublicationCandidatesAction
{
    /**
     * Live draft Products whose effective human decision is FEATURE, KEEP, or KEEP_NICHE.
     *
     * @return Collection<int, Product>
     */
    public function execute(): Collection
    {
        return Product::query()
            ->where('status', ProductStatus::Draft)
            ->whereHas('currentCurationDecision', function ($query): void {
                $query->whereIn('decision', $this->eligibleDecisionValues());
            })
            ->with([
                'currentCurationDecision',
                'images',
                'affiliateLinks',
                'categories',
                'relationships',
                'occasions',
                'interests',
                'giftTypes',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function eligibleDecisionValues(): array
    {
        return array_values(array_map(
            fn (ProductCurationDecision $decision): string => $decision->value,
            array_filter(
                ProductCurationDecision::cases(),
                fn (ProductCurationDecision $decision): bool => $decision->isKeepFamily(),
            ),
        ));
    }
}
