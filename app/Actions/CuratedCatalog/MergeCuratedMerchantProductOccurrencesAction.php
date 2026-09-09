<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedSourceListContext;
use App\CuratedCatalog\MergedCuratedMerchantProduct;
use Illuminate\Support\Carbon;
use Throwable;

class MergeCuratedMerchantProductOccurrencesAction
{
    /**
     * @param  list<CuratedMerchantProductInput>  $occurrences
     * @return list<MergedCuratedMerchantProduct>
     */
    public function execute(array $occurrences): array
    {
        $groups = [];

        foreach ($occurrences as $occurrence) {
            $groups[$occurrence->identityKey()][] = $occurrence;
        }

        $merged = [];

        foreach ($groups as $group) {
            $merged[] = $this->mergeGroup($group);
        }

        usort(
            $merged,
            fn (MergedCuratedMerchantProduct $left, MergedCuratedMerchantProduct $right): int => $left->input->itemIndex <=> $right->input->itemIndex,
        );

        return $merged;
    }

    /**
     * @param  list<CuratedMerchantProductInput>  $group
     */
    private function mergeGroup(array $group): MergedCuratedMerchantProduct
    {
        $ordered = $this->sortByRecency($group);
        $first = $ordered[0];
        $title = $first->title;
        $priceAmount = $first->priceAmount;
        $priceCurrency = $first->priceCurrency;
        $sourceUrl = $first->sourceUrl;
        $sourceImageUrl = $first->sourceImageUrl;
        $availability = $first->availability;
        $capturedAt = $first->capturedAt;
        $conflicts = [];

        foreach (array_slice($ordered, 1) as $occurrence) {
            if ($occurrence->title !== '' && $occurrence->title !== $title) {
                $conflicts[] = 'title';
                $title = $occurrence->title;
            }

            if ($occurrence->priceAmount !== null) {
                if ($priceAmount !== null && $occurrence->priceAmount !== $priceAmount) {
                    $conflicts[] = 'price';
                }

                $priceAmount = $occurrence->priceAmount;
                $priceCurrency = $occurrence->priceCurrency ?? $priceCurrency;
            }

            $preferredUrl = $this->preferSourceUrl($sourceUrl, $occurrence->sourceUrl, $occurrence->externalProductId);

            if ($this->urlsMateriallyDiffer($sourceUrl, $occurrence->sourceUrl)) {
                $conflicts[] = 'source_url';
            }

            $sourceUrl = $preferredUrl;

            if ($occurrence->sourceImageUrl !== null) {
                if ($sourceImageUrl !== null && $occurrence->sourceImageUrl !== $sourceImageUrl) {
                    $conflicts[] = 'image';
                }

                $sourceImageUrl = $occurrence->sourceImageUrl;
            }

            if ($occurrence->availability !== null) {
                if ($availability !== null && $occurrence->availability !== $availability) {
                    $conflicts[] = 'availability';
                }

                $availability = $occurrence->availability;
            }

            if ($occurrence->capturedAt !== null) {
                $capturedAt = $occurrence->capturedAt;
            }
        }

        $representative = $ordered[array_key_last($ordered)]->withMergedCommercialFields(
            sourceUrl: $sourceUrl,
            title: $title,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            sourceImageUrl: $sourceImageUrl,
            availability: $availability,
            capturedAt: $capturedAt,
        );

        return new MergedCuratedMerchantProduct(
            merchantSlug: $first->merchantSlug,
            externalProductId: $first->externalProductId,
            input: $representative,
            occurrences: $group,
            sourceLists: $this->uniqueSourceLists($group),
            conflicts: array_values(array_unique($conflicts)),
        );
    }

    /**
     * @param  list<CuratedMerchantProductInput>  $group
     * @return list<CuratedMerchantProductInput>
     */
    private function sortByRecency(array $group): array
    {
        $indexed = [];

        foreach ($group as $index => $occurrence) {
            $indexed[] = [$index, $occurrence];
        }

        usort($indexed, function (array $left, array $right): int {
            $leftAt = $this->parseCapturedAt($left[1]->capturedAt);
            $rightAt = $this->parseCapturedAt($right[1]->capturedAt);

            if ($leftAt !== null && $rightAt !== null && $leftAt !== $rightAt) {
                return $leftAt <=> $rightAt;
            }

            if ($leftAt !== null && $rightAt === null) {
                return 1;
            }

            if ($leftAt === null && $rightAt !== null) {
                return -1;
            }

            return $left[0] <=> $right[0];
        });

        return array_map(fn (array $row): CuratedMerchantProductInput => $row[1], $indexed);
    }

    private function parseCapturedAt(?string $capturedAt): ?int
    {
        if ($capturedAt === null || $capturedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($capturedAt)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function preferSourceUrl(string $current, string $candidate, string $externalProductId): string
    {
        $currentScore = $this->sourceUrlScore($current, $externalProductId);
        $candidateScore = $this->sourceUrlScore($candidate, $externalProductId);

        if ($candidateScore > $currentScore) {
            return $candidate;
        }

        if ($candidateScore < $currentScore) {
            return $current;
        }

        return $candidate;
    }

    private function sourceUrlScore(string $url, string $externalProductId): int
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? strtolower((string) ($parts['path'] ?? '')) : '';
        $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
        $asin = strtolower($externalProductId);

        $isCanonicalProductPath = (bool) preg_match('#^/dp/'.preg_quote($asin, '#').'/?$#', $path)
            || (bool) preg_match('#^/gp/product/'.preg_quote($asin, '#').'/?$#', $path);

        if ($isCanonicalProductPath && $query === '') {
            return 3;
        }

        if ($isCanonicalProductPath) {
            return 2;
        }

        if (str_contains($path, '/dp/'.$asin) || str_contains($path, '/gp/product/'.$asin)) {
            return 1;
        }

        return 0;
    }

    private function urlsMateriallyDiffer(string $left, string $right): bool
    {
        $normalize = static function (string $url): string {
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $path = is_array($parts) ? rtrim(strtolower((string) ($parts['path'] ?? '')), '/') : '';

            return $host.$path;
        };

        return $normalize($left) !== $normalize($right) || $left !== $right;
    }

    /**
     * @param  list<CuratedMerchantProductInput>  $group
     * @return list<CuratedSourceListContext>
     */
    private function uniqueSourceLists(array $group): array
    {
        $unique = [];

        foreach ($group as $occurrence) {
            $context = $occurrence->sourceListContext;

            if (! $context instanceof CuratedSourceListContext || ! $context->isPresent()) {
                continue;
            }

            $key = $context->identityKey() ?? spl_object_hash($context);
            $unique[$key] = $context;
        }

        return array_values($unique);
    }
}
