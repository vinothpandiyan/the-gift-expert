<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Import\UpsertImportedProductAction;
use App\CuratedCatalog\Affiliate\BuildCuratedAffiliateUrlAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductIntakeItemResult;
use App\Enums\TaxonomyClassificationStatus;
use App\Import\ImportedCatalogItem;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateCuratedMerchantProductAction
{
    public function __construct(
        private BuildCuratedAffiliateUrlAction $buildAffiliateUrl,
        private UpsertImportedProductAction $upsertImportedProduct,
        private AcquireCuratedProductImageAction $acquireImage,
        private ClassifyCuratedMerchantProductAction $classify,
    ) {}

    /**
     * @param  list<string>  $previewWarnings
     */
    public function execute(
        Merchant $merchant,
        CuratedMerchantProductInput $input,
        array $previewWarnings = [],
        bool $deferClassification = false,
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

        $warnings = $previewWarnings;

        try {
            $result = DB::transaction(function () use ($merchant, $input, $affiliate): array {
                return $this->persistCanonicalDraft($merchant, $input, $affiliate->url);
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

        if ($deferClassification) {
            $warnings = array_values(array_unique(array_merge($warnings, ['classification_deferred'])));
        } else {
            $classified = $this->classify->execute($result['product']->fresh());
            $warnings = array_values(array_unique(array_merge($warnings, $classified->warnings)));

            if ($classified->status === TaxonomyClassificationStatus::Failed) {
                $warnings[] = 'classification_failed';
            }

            if ($classified->status === TaxonomyClassificationStatus::Review) {
                $warnings[] = 'classification_review';
            }
        }

        return new CuratedProductIntakeItemResult(
            success: true,
            outcome: 'created',
            productId: $result['product_id'],
            affiliateLinkId: $result['affiliate_link_id'],
            warnings: array_values(array_unique($warnings)),
            error: null,
            imageStatus: $imageOutcome->status,
        );
    }

    /**
     * Create the canonical draft Product + AffiliateLink from curated source data.
     *
     * Classification is optional. Deferred drafts use the extracted title and
     * leave taxonomy/editorial copy empty until ClassifyCuratedMerchantProductAction.
     */
    private function persistCanonicalDraft(
        Merchant $merchant,
        CuratedMerchantProductInput $input,
        string $affiliateUrl,
    ): array {
        $imported = new ImportedCatalogItem(
            name: $input->title,
            description: null,
            short_description: null,
            brand: null,
            price_amount: $input->priceAmount,
            price_currency: $input->priceCurrency,
            affiliate_url: $affiliateUrl,
            external_product_id: $input->externalProductId,
            image_urls: [],
            raw: [
                'curated_intake' => $input->sourcePayload,
                'classification_deferred' => true,
            ],
        );

        $link = $this->upsertImportedProduct->execute($merchant, $imported);
        $product = $link->product()->firstOrFail();
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::None;
        $product->save();

        $this->touchAffiliateObservability($link, $input);

        return [
            'product_id' => $product->id,
            'affiliate_link_id' => $link->id,
            'product' => $product->fresh(),
        ];
    }

    private function touchAffiliateObservability(AffiliateLink $link, CuratedMerchantProductInput $input): void
    {
        $link->availability = $input->availability;
        $link->last_seen_at = now();
        $link->save();
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
