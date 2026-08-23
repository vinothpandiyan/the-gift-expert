<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Import\UpsertImportedProductAction;
use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\CuratedCatalog\Affiliate\BuildCuratedAffiliateUrlAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductIntakeItemResult;
use App\Import\ImportedCatalogItem;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateCuratedMerchantProductAction
{
    public function __construct(
        private BuildCuratedAffiliateUrlAction $buildAffiliateUrl,
        private EnrichCuratedMerchantProductAction $enrich,
        private UpsertImportedProductAction $upsertImportedProduct,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
        private AcquireCuratedProductImageAction $acquireImage,
    ) {}

    /**
     * @param  list<string>  $previewWarnings
     */
    public function execute(
        Merchant $merchant,
        CuratedMerchantProductInput $input,
        array $previewWarnings = [],
    ): CuratedProductIntakeItemResult {
        if ($this->hasTrashedIdentity($merchant, $input->externalProductId)) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'skipped',
                productId: null,
                affiliateLinkId: null,
                warnings: array_values(array_unique(array_merge($previewWarnings, ['trashed_identity']))),
                error: 'trashed_identity',
            );
        }

        $affiliate = $this->buildAffiliateUrl->execute($merchant, $input);

        if (! $affiliate->ready || ! is_string($affiliate->url) || $affiliate->url === '') {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: null,
                warnings: array_values(array_unique(array_merge($previewWarnings, ['affiliate_not_ready']))),
                error: $affiliate->reasonCode ?? 'affiliate_not_ready',
            );
        }

        try {
            $enrichment = $this->enrich->execute($merchant, $input);
        } catch (Throwable $exception) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: null,
                warnings: $previewWarnings,
                error: $exception->getMessage(),
            );
        }

        $warnings = array_values(array_unique(array_merge($previewWarnings, $enrichment->warnings)));

        try {
            $result = DB::transaction(function () use ($merchant, $input, $affiliate, $enrichment): array {
                $imported = new ImportedCatalogItem(
                    name: $enrichment->name,
                    description: $enrichment->description,
                    short_description: $enrichment->shortDescription,
                    brand: $enrichment->brand,
                    price_amount: $input->priceAmount,
                    price_currency: $input->priceCurrency,
                    affiliate_url: $affiliate->url,
                    external_product_id: $input->externalProductId,
                    image_urls: [],
                    raw: [
                        'curated_intake' => $input->sourcePayload,
                        'enrichment_metadata' => $enrichment->metadata,
                    ],
                );

                $link = $this->upsertImportedProduct->execute($merchant, $imported);
                $product = $link->product()->firstOrFail();
                $this->applyTaxonomy->execute($product->fresh(), $enrichment->toTaxonomyClassification());

                return [
                    'product_id' => $product->id,
                    'affiliate_link_id' => $link->id,
                    'product' => $product->fresh(),
                ];
            });
        } catch (Throwable $exception) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: null,
                warnings: $warnings,
                error: $exception->getMessage(),
            );
        }

        $imageOutcome = $this->acquireImage->execute($merchant, $result['product'], $input->sourceImageUrl);
        $warnings = $this->mergeImageOutcome($warnings, $imageOutcome);

        return new CuratedProductIntakeItemResult(
            success: true,
            outcome: 'created',
            productId: $result['product_id'],
            affiliateLinkId: $result['affiliate_link_id'],
            warnings: $warnings,
            error: null,
            imageStatus: $imageOutcome->status,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function mergeImageOutcome(array $warnings, CuratedImageAcquisitionOutcome $outcome): array
    {
        return array_values(array_unique(array_merge($warnings, $outcome->auditCodes())));
    }

    private function hasTrashedIdentity(Merchant $merchant, string $externalProductId): bool
    {
        return AffiliateLink::onlyTrashed()
            ->where('merchant_id', $merchant->id)
            ->where('external_product_id', $externalProductId)
            ->exists();
    }
}
