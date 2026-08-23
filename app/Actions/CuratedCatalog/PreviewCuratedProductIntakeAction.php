<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\Affiliate\BuildCuratedAffiliateUrlAction;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\CuratedCatalog\CuratedProductIntakePreview;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;

class PreviewCuratedProductIntakeAction
{
    public function __construct(
        private ParseCuratedMerchantProductsAction $parse,
        private BuildCuratedAffiliateUrlAction $buildAffiliateUrl,
    ) {}

    public function execute(
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
    ): CuratedProductIntakePreview {
        $parsed = $this->parse->execute($json, $formMerchantSlug, $formCurationGroup);
        $merchantSlug = $formMerchantSlug ?? $this->resolveMerchantSlug($json);

        $merchant = Merchant::query()
            ->where('slug', $merchantSlug)
            ->where('is_active', true)
            ->first();

        $validInputs = [];
        $seenAsins = [];

        foreach ($parsed as $row) {
            if ($row instanceof CuratedMerchantProductInput) {
                $validInputs[] = $row;
            }
        }

        $identityMap = $this->resolveIdentities($merchant, $validInputs);
        $previewItems = [];

        $counts = [
            'valid' => 0,
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
        ];

        foreach ($parsed as $row) {
            if ($row instanceof CuratedProductInputError) {
                $previewItems[] = new CuratedProductIntakePreviewItem(
                    itemIndex: $row->itemIndex,
                    input: null,
                    error: $row,
                    disposition: 'invalid',
                    proposedAction: 'FAIL',
                    warnings: [],
                    affiliateReady: false,
                    affiliateReasonCode: null,
                    productId: null,
                    affiliateLinkId: null,
                );
                $counts['invalid']++;

                continue;
            }

            $warnings = [];
            $disposition = 'new';
            $proposedAction = 'CREATE';
            $affiliateReady = false;
            $affiliateReasonCode = null;
            $productId = null;
            $affiliateLinkId = null;

            if (isset($seenAsins[$row->externalProductId])) {
                $disposition = 'duplicate';
                $proposedAction = 'SKIP';
                $warnings[] = 'duplicate_in_payload';
                $counts['duplicate']++;
                $counts['valid']++;
                $previewItems[] = new CuratedProductIntakePreviewItem(
                    itemIndex: $row->itemIndex,
                    input: $row,
                    error: null,
                    disposition: $disposition,
                    proposedAction: $proposedAction,
                    warnings: $warnings,
                    affiliateReady: false,
                    affiliateReasonCode: null,
                    productId: null,
                    affiliateLinkId: null,
                );

                continue;
            }

            $seenAsins[$row->externalProductId] = true;
            $counts['valid']++;

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

            if (in_array($proposedAction, ['CREATE', 'UPDATE'], true)) {
                $counts['actionable']++;
            }

            $previewItems[] = new CuratedProductIntakePreviewItem(
                itemIndex: $row->itemIndex,
                input: $row,
                error: null,
                disposition: $disposition,
                proposedAction: $proposedAction,
                warnings: $warnings,
                affiliateReady: $affiliateReady,
                affiliateReasonCode: $affiliateReasonCode,
                productId: $productId,
                affiliateLinkId: $affiliateLinkId,
            );
        }

        return new CuratedProductIntakePreview(
            merchantSlug: (string) $merchantSlug,
            itemsTotal: count($parsed),
            itemsValid: $counts['valid'],
            itemsInvalid: $counts['invalid'],
            itemsNew: $counts['new'],
            itemsExisting: $counts['existing'],
            itemsDuplicate: $counts['duplicate'],
            itemsTrashed: $counts['trashed'],
            itemsMissingPrice: $counts['missing_price'],
            itemsMissingImage: $counts['missing_image'],
            itemsUnavailable: $counts['unavailable'],
            itemsAffiliateNotReady: $counts['affiliate_not_ready'],
            itemsActionable: $counts['actionable'],
            items: $previewItems,
        );
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
