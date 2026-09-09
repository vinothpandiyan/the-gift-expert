<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\Affiliate\BuildCuratedAffiliateUrlAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductIntakeItemResult;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefreshCuratedMerchantProductAction
{
    public function __construct(
        private BuildCuratedAffiliateUrlAction $buildAffiliateUrl,
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
        $link = AffiliateLink::query()
            ->where('merchant_id', $merchant->id)
            ->where('external_product_id', $input->externalProductId)
            ->first();

        if (! $link instanceof AffiliateLink) {
            if (AffiliateLink::onlyTrashed()
                ->where('merchant_id', $merchant->id)
                ->where('external_product_id', $input->externalProductId)
                ->exists()) {
                return new CuratedProductIntakeItemResult(
                    success: false,
                    outcome: 'skipped',
                    productId: null,
                    affiliateLinkId: null,
                    warnings: array_values(array_unique(array_merge($previewWarnings, ['trashed_identity']))),
                    error: 'trashed_identity',
                );
            }

            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: null,
                warnings: $previewWarnings,
                error: 'missing_identity',
            );
        }

        $product = Product::query()->find($link->product_id);

        if (! $product instanceof Product) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: $link->id,
                warnings: $previewWarnings,
                error: 'missing_product',
            );
        }

        if ($product->status === ProductStatus::Archived) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'skipped',
                productId: $product->id,
                affiliateLinkId: $link->id,
                warnings: array_values(array_unique(array_merge($previewWarnings, ['archived_product']))),
                error: 'archived_product',
            );
        }

        $affiliate = $this->buildAffiliateUrl->execute($merchant, $input);
        $warnings = $previewWarnings;

        if (! $affiliate->ready) {
            $warnings = array_values(array_unique(array_merge($warnings, ['affiliate_not_ready'])));
        }

        try {
            DB::transaction(function () use ($link, $product, $input, $affiliate): void {
                $originalName = $product->name;
                $originalShortDescription = $product->short_description;
                $originalDescription = $product->description;
                $originalBrand = $product->brand;

                if ($input->priceAmount !== null) {
                    $product->price_amount = $input->priceAmount;
                    $product->price_currency = $input->priceCurrency ?? $product->price_currency;
                }

                $product->name = $originalName;
                $product->short_description = $originalShortDescription;
                $product->description = $originalDescription;
                $product->brand = $originalBrand;
                $product->save();

                if ($affiliate->ready && is_string($affiliate->url) && $affiliate->url !== '') {
                    $link->url = $affiliate->url;
                    $link->status = AffiliateLinkStatus::Active;
                }

                $link->last_verified_at = $this->resolveVerifiedAt($input->capturedAt);
                $link->availability = $input->availability ?? $link->availability;
                $link->last_seen_at = $this->resolveVerifiedAt($input->capturedAt);
                $link->save();
            });
        } catch (Throwable $exception) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: $product->id,
                affiliateLinkId: $link->id,
                warnings: $warnings,
                error: $exception->getMessage(),
            );
        }

        $imageOutcome = $this->acquireImage->execute($merchant, $product->fresh(), $input->sourceImageUrl);
        $warnings = $this->mergeImageOutcome($warnings, $imageOutcome);

        return new CuratedProductIntakeItemResult(
            success: true,
            outcome: 'updated',
            productId: $product->id,
            affiliateLinkId: $link->id,
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

    private function resolveVerifiedAt(?string $capturedAt): Carbon
    {
        if (is_string($capturedAt) && $capturedAt !== '') {
            try {
                return Carbon::parse($capturedAt);
            } catch (Throwable) {
            }
        }

        return now();
    }
}
