<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\Affiliate\BuildCuratedAffiliateUrlAction;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\CuratedCatalog\CuratedProductIntakePreview;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use App\CuratedCatalog\CuratedSourceListContext;
use App\CuratedCatalog\MergedCuratedMerchantProduct;
use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Relationship;

class PreviewCuratedProductIntakeAction
{
    public function __construct(
        private ParseCuratedMerchantProductsAction $parse,
        private MergeCuratedMerchantProductOccurrencesAction $merge,
        private BuildCuratedAffiliateUrlAction $buildAffiliateUrl,
        private ResolveCatalogSourceListMappingAction $mapping,
    ) {}

    public function execute(
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
    ): CuratedProductIntakePreview {
        $parsed = $this->parse->execute($json, $formMerchantSlug, $formCurationGroup);
        $merchantSlug = $formMerchantSlug ?? $this->resolveMerchantSlug($json);

        return $this->fromParsed($parsed, (string) $merchantSlug);
    }

    /**
     * @param  list<CuratedMerchantProductInput|CuratedProductInputError>  $parsed
     */
    public function fromParsed(array $parsed, string $merchantSlug): CuratedProductIntakePreview
    {
        $merchant = Merchant::query()
            ->where('slug', $merchantSlug)
            ->where('is_active', true)
            ->first();

        $validInputs = [];
        $errors = [];

        foreach ($parsed as $row) {
            if ($row instanceof CuratedMerchantProductInput) {
                $validInputs[] = $row;
            } else {
                $errors[] = $row;
            }
        }

        $mergedProducts = $this->merge->execute($validInputs);
        $identityMap = $this->resolveIdentities($merchant, $validInputs);

        $counts = [
            'valid' => count($validInputs),
            'invalid' => 0,
            'new' => 0,
            'existing' => 0,
            'duplicate' => 0,
            'trashed' => 0,
            'missing_price' => 0,
            'missing_image' => 0,
            'unavailable' => 0,
            'affiliate_not_ready' => 0,
            'actionable' => 0,
            'multi_list' => 0,
        ];

        $previewItems = [];

        foreach ($errors as $error) {
            $previewItems[] = new CuratedProductIntakePreviewItem(
                itemIndex: $error->itemIndex,
                input: null,
                error: $error,
                disposition: 'invalid',
                proposedAction: 'FAIL',
                warnings: [],
                affiliateReady: false,
                affiliateReasonCode: null,
                productId: null,
                affiliateLinkId: null,
            );
            $counts['invalid']++;
        }

        foreach ($mergedProducts as $merged) {
            $previewItems[] = $this->previewMergedProduct(
                $merged,
                $merchant,
                $identityMap,
                $counts,
            );
        }

        usort(
            $previewItems,
            fn (CuratedProductIntakePreviewItem $left, CuratedProductIntakePreviewItem $right): int => $left->itemIndex <=> $right->itemIndex,
        );

        $uniqueProducts = count($mergedProducts);
        $mergedOccurrences = max($counts['valid'] - $uniqueProducts, 0);

        return new CuratedProductIntakePreview(
            merchantSlug: $merchantSlug,
            itemsTotal: count($previewItems),
            itemsValid: $counts['valid'],
            itemsInvalid: $counts['invalid'],
            itemsNew: $counts['new'],
            itemsExisting: $counts['existing'],
            itemsDuplicate: $mergedOccurrences,
            itemsTrashed: $counts['trashed'],
            itemsMissingPrice: $counts['missing_price'],
            itemsMissingImage: $counts['missing_image'],
            itemsUnavailable: $counts['unavailable'],
            itemsAffiliateNotReady: $counts['affiliate_not_ready'],
            itemsActionable: $counts['actionable'],
            items: $previewItems,
            rawOccurrences: $counts['valid'],
            uniqueProducts: $uniqueProducts,
            mergedOccurrences: $mergedOccurrences,
            multiListProducts: $counts['multi_list'],
        );
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, array{trashed: bool, product_id: ?int, affiliate_link_id: ?int, product_status: ?string}>  $identityMap
     */
    private function previewMergedProduct(
        MergedCuratedMerchantProduct $merged,
        ?Merchant $merchant,
        array $identityMap,
        array &$counts,
    ): CuratedProductIntakePreviewItem {
        $row = $merged->input;
        $warnings = [];
        $disposition = 'new';
        $proposedAction = 'CREATE';
        $affiliateReady = false;
        $affiliateReasonCode = null;
        $productId = null;
        $affiliateLinkId = null;

        if ($merged->mergedOccurrenceCount() > 0) {
            $warnings[] = 'merged_occurrences';
        }

        if ($merged->conflicts !== []) {
            $warnings[] = 'commercial_conflicts';
        }

        if ($row->priceAmount === null) {
            $warnings[] = 'missing_price';
            $counts['missing_price']++;
        }

        if ($row->sourceImageUrl === null) {
            $warnings[] = 'missing_image_url';
            $counts['missing_image']++;
        }

        if (in_array($row->availability, ['out_of_stock', 'unavailable'], true)) {
            $warnings[] = 'availability_unavailable';
            $counts['unavailable']++;
        }

        $identity = $identityMap[$row->externalProductId] ?? null;

        if ($identity !== null) {
            if ($identity['trashed']) {
                $disposition = 'trashed';
                $proposedAction = 'SKIP';
                $warnings[] = 'trashed_identity';
                $counts['trashed']++;
            } else {
                $disposition = 'existing';
                $proposedAction = 'UPDATE';
                $productId = $identity['product_id'];
                $affiliateLinkId = $identity['affiliate_link_id'];
                $counts['existing']++;

                $productStatus = $identity['product_status'];

                if ($productStatus === ProductStatus::Archived->value) {
                    $proposedAction = 'SKIP';
                    $warnings[] = 'archived_product';
                }
            }
        } else {
            $counts['new']++;
        }

        if ($merchant instanceof Merchant && in_array($proposedAction, ['CREATE', 'UPDATE'], true)) {
            $affiliate = $this->buildAffiliateUrl->execute($merchant, $row);
            $affiliateReady = $affiliate->ready;
            $affiliateReasonCode = $affiliate->reasonCode;

            if (! $affiliateReady) {
                $warnings[] = 'affiliate_not_ready';
                $counts['affiliate_not_ready']++;

                if ($proposedAction === 'CREATE') {
                    $proposedAction = 'FAIL';
                }
            }
        } elseif ($proposedAction === 'CREATE') {
            $warnings[] = 'merchant_not_active';
            $proposedAction = 'FAIL';
        }

        foreach ($merged->sourceLists as $sourceList) {
            if ($sourceList->malformed) {
                $warnings[] = 'malformed_source_list';
            }

            $mapping = $this->mapping->execute($row->merchantSlug, $sourceList);

            if (! $mapping->isMapped) {
                $warnings[] = 'needs_source_mapping';
            }
        }

        if (in_array($proposedAction, ['CREATE', 'UPDATE'], true)) {
            $counts['actionable']++;
        }

        if ($merged->isMultiList()) {
            $counts['multi_list']++;
        }

        return new CuratedProductIntakePreviewItem(
            itemIndex: $row->itemIndex,
            input: $row,
            error: null,
            disposition: $disposition,
            proposedAction: $proposedAction,
            warnings: array_values(array_unique($warnings)),
            affiliateReady: $affiliateReady,
            affiliateReasonCode: $affiliateReasonCode,
            productId: $productId,
            affiliateLinkId: $affiliateLinkId,
            sourceLists: $merged->sourceLists,
            sourceListNames: $merged->sourceListNames(),
            relationshipHintNames: $this->relationshipHintNames($row->merchantSlug, $merged->sourceLists),
            commercialConflicts: $merged->conflicts,
            occurrencesMerged: $merged->occurrenceCount(),
            merged: $merged,
        );
    }

    /**
     * @param  list<CuratedSourceListContext>  $sourceLists
     * @return list<string>
     */
    private function relationshipHintNames(string $merchantSlug, array $sourceLists): array
    {
        $names = [];

        foreach ($sourceLists as $sourceList) {
            $mapping = $this->mapping->execute($merchantSlug, $sourceList);

            if ($mapping->kind !== CatalogSourceListKind::RecipientHint->value || $mapping->relationshipSlug === null) {
                continue;
            }

            $relationship = Relationship::query()
                ->where('slug', $mapping->relationshipSlug)
                ->where('is_active', true)
                ->first();

            if ($relationship instanceof Relationship) {
                $names[] = $relationship->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<CuratedMerchantProductInput>  $inputs
     * @return array<string, array{trashed: bool, product_id: ?int, affiliate_link_id: ?int, product_status: ?string}>
     */
    private function resolveIdentities(?Merchant $merchant, array $inputs): array
    {
        if (! $merchant instanceof Merchant || $inputs === []) {
            return [];
        }

        $externalIds = array_values(array_unique(array_map(
            fn (CuratedMerchantProductInput $input): string => $input->externalProductId,
            $inputs,
        )));

        $links = AffiliateLink::withTrashed()
            ->with(['product' => fn ($query) => $query->withTrashed()])
            ->where('merchant_id', $merchant->id)
            ->whereIn('external_product_id', $externalIds)
            ->get();

        $map = [];

        foreach ($links as $link) {
            $map[$link->external_product_id] = [
                'trashed' => $link->trashed() || $link->product?->trashed() === true,
                'product_id' => $link->product_id,
                'affiliate_link_id' => $link->id,
                'product_status' => $link->product?->status?->value,
            ];
        }

        return $map;
    }

    private function resolveMerchantSlug(string $json): string
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['merchant'] ?? ''));
    }
}
