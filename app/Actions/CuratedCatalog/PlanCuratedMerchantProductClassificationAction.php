<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedClassificationPlan;
use App\CuratedCatalog\CuratedClassificationPlanItem;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Support\Collection;

class PlanCuratedMerchantProductClassificationAction
{
    public function __construct(
        private QueryProductsForCuratedClassificationAction $queryProducts,
        private ShouldReclassifyCuratedMerchantProductAction $shouldReclassify,
        private ResolveCatalogSourceRelationshipHintsAction $hints,
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
    ) {}

    public function execute(
        ?int $intakeRunId = null,
        ?int $productId = null,
        ?string $merchantSlug = null,
        ?string $status = null,
        ?int $limit = null,
        bool $force = false,
        bool $retryFailed = false,
    ): CuratedClassificationPlan {
        $products = $this->queryProducts->execute(
            $intakeRunId,
            $productId,
            $merchantSlug,
            $status,
            $limit,
        );

        $version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $maxOutputTokens = max(0, (int) config('commercial_sourcing.enrichment.max_output_tokens', 2500));
        $hintSlugsById = Relationship::query()
            ->where('is_active', true)
            ->pluck('slug', 'id');

        $items = [];
        $eligible = 0;
        $skippedCurrent = 0;
        $skippedLocked = 0;
        $skippedOther = 0;
        $withHints = 0;
        $withoutHints = 0;
        $failedWouldRetry = 0;
        $failedWouldSkip = 0;

        foreach ($products as $product) {
            $decision = $this->shouldReclassify->execute($product, $force, $retryFailed);
            $hintIds = $this->hints->execute($product);
            $statusValue = $product->taxonomy_classification_status?->value
                ?? TaxonomyClassificationStatus::None->value;

            if ($statusValue === TaxonomyClassificationStatus::Failed->value) {
                if ($retryFailed || $force) {
                    $failedWouldRetry++;
                } else {
                    $failedWouldSkip++;
                }
            }

            if ($hintIds !== []) {
                $withHints++;
            } else {
                $withoutHints++;
            }

            if ($decision->shouldReclassify) {
                $eligible++;
            } elseif ($decision->reason === 'human_locked') {
                $skippedLocked++;
            } elseif (in_array($decision->reason, ['current', 'failed_not_retried', 'archived'], true)) {
                $skippedCurrent++;
            } else {
                $skippedOther++;
            }

            $items[] = new CuratedClassificationPlanItem(
                productId: $product->id,
                status: $statusValue,
                version: $product->taxonomy_classification_version,
                decisionReason: $decision->reason,
                eligible: $decision->shouldReclassify,
                hintSlugs: $this->hintSlugs($hintIds, $hintSlugsById),
                externalProductId: $this->externalId($product),
                merchantSlug: $this->merchantSlug($product),
                sourceTitle: $this->contentFingerprint->sourceTitle($product),
            );
        }

        return new CuratedClassificationPlan(
            totalConsidered: $products->count(),
            eligible: $eligible,
            skippedCurrent: $skippedCurrent,
            skippedLocked: $skippedLocked,
            skippedOther: $skippedOther,
            withTrustedHints: $withHints,
            withoutRecipientHints: $withoutHints,
            failedWouldRetry: $failedWouldRetry,
            failedWouldSkip: $failedWouldSkip,
            classificationVersion: $version,
            estimatedMaxOutputTokens: $eligible * $maxOutputTokens,
            items: $items,
            intakeRunId: $intakeRunId,
        );
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, string>  $slugsById
     * @return list<string>
     */
    private function hintSlugs(array $ids, $slugsById): array
    {
        $slugs = [];

        foreach ($ids as $id) {
            $slug = $slugsById[$id] ?? null;

            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    private function externalId(Product $product): ?string
    {
        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();

        return $link?->external_product_id;
    }

    private function merchantSlug(Product $product): ?string
    {
        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();

        return $link?->merchant?->slug;
    }
}
