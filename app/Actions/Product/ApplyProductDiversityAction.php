<?php

namespace App\Actions\Product;

use App\DiscoveryRanking\RankedGift;
use App\Models\Product;

class ApplyProductDiversityAction
{
    /**
     * @param  list<RankedGift>  $ranked
     * @return list<RankedGift>
     */
    public function execute(array $ranked): array
    {
        if ($ranked === [] || config('discovery_ranking.diversity.enabled') !== true) {
            return $ranked;
        }

        $window = max(1, (int) config('discovery_ranking.diversity.top_window', 12));
        $maxGap = (float) config('discovery_ranking.diversity.max_score_gap_for_diversity_swap', 15);
        $relax = config('discovery_ranking.diversity.relax_when_insufficient', true) === true;

        $output = [];
        $pool = array_values($ranked);
        $targetWindow = min($window, count($pool));

        while (count($output) < $targetWindow && $pool !== []) {
            $placed = $this->placeNextCandidate($pool, $output, $maxGap, $relax);

            if ($placed === null) {
                break;
            }

            $output[] = $placed;
        }

        $placedIds = collect($output)->map(fn (RankedGift $gift) => $gift->product->id)->all();
        $remaining = array_values(array_filter(
            $ranked,
            fn (RankedGift $gift): bool => ! in_array($gift->product->id, $placedIds, true),
        ));

        return array_merge($output, $remaining);
    }

    /**
     * @param  list<RankedGift>  $pool
     * @param  list<RankedGift>  $output
     */
    private function placeNextCandidate(array &$pool, array $output, float $maxGap, bool $relax): ?RankedGift
    {
        foreach ($pool as $index => $candidate) {
            if ($this->fitsDiversity($candidate, $output)) {
                unset($pool[$index]);
                $pool = array_values($pool);

                return $candidate;
            }
        }

        if ($pool === []) {
            return null;
        }

        $top = $pool[0];
        $topScore = $top->score;

        foreach ($pool as $index => $candidate) {
            if ($index === 0) {
                continue;
            }

            if (($topScore - $candidate->score) > $maxGap) {
                break;
            }

            if ($this->fitsDiversity($candidate, $output)) {
                unset($pool[$index]);
                $pool = array_values($pool);

                return $candidate;
            }
        }

        if (! $relax) {
            return null;
        }

        $candidate = $pool[0];
        array_shift($pool);

        return $candidate;
    }

    /**
     * @param  list<RankedGift>  $output
     */
    private function fitsDiversity(RankedGift $candidate, array $output): bool
    {
        $window = max(1, (int) config('discovery_ranking.diversity.top_window', 12));
        $maxSameCategory = (int) config('discovery_ranking.diversity.max_same_primary_category_in_window', 3);
        $maxConsecutive = (int) config('discovery_ranking.diversity.max_consecutive_same_primary_category', 2);

        $windowOutput = array_slice($output, 0, $window);
        $candidateCategoryId = $this->primaryCategoryId($candidate->product);

        if ($candidateCategoryId === null) {
            return true;
        }

        $sameCategoryCount = 0;

        foreach ($windowOutput as $placed) {
            if ($this->primaryCategoryId($placed->product) === $candidateCategoryId) {
                $sameCategoryCount++;
            }
        }

        if ($sameCategoryCount >= $maxSameCategory) {
            return false;
        }

        if ($output !== []) {
            $consecutive = 1;
            $lastCategoryId = $this->primaryCategoryId($output[array_key_last($output)]->product);

            if ($lastCategoryId === $candidateCategoryId) {
                $consecutive++;

                for ($index = count($output) - 2; $index >= 0; $index--) {
                    if ($this->primaryCategoryId($output[$index]->product) !== $candidateCategoryId) {
                        break;
                    }

                    $consecutive++;
                }
            }

            if ($consecutive > $maxConsecutive) {
                return false;
            }
        }

        return true;
    }

    private function primaryCategoryId(Product $product): ?int
    {
        $categories = $product->relationLoaded('categories')
            ? $product->categories
            : $product->categories()->get();

        $primary = $categories->first(fn ($category) => (bool) $category->pivot?->is_primary);

        return $primary?->id;
    }
}
