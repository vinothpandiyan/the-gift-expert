<?php

namespace App\Actions\CatalogCandidate;

use App\Actions\Import\StoreImportedProductImagesAction;
use App\Actions\Import\UpsertImportedProductAction;
use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\CommercialSourcing\CatalogCandidatePromotionResult;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ProductPromotionPayload;
use App\Enums\ProductStatus;
use App\Import\ProviderImagePolicy;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\Merchant;
use App\Models\Product;
use InvalidArgumentException;
use Throwable;

class PromoteCatalogCandidateSourcingItemAction
{
    public function __construct(
        private UpsertImportedProductAction $upsertImportedProduct,
        private StoreImportedProductImagesAction $storeImportedProductImages,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
        private CommercialSourcingMerchants $merchants,
        private EvaluateAndPersistProductAutomationReadinessAction $evaluateReadiness,
    ) {}

    public function execute(CatalogCandidateSourcingItem $item, bool $dryRun = false): CatalogCandidatePromotionResult
    {
        $gate = $this->validateGate($item);

        if (! $gate['promotable']) {
            return new CatalogCandidatePromotionResult(
                promoted: false,
                productId: null,
                affiliateLinkId: null,
                exceptionCodes: $gate['codes'],
                error: 'Promotion gate failed: '.implode(', ', $gate['codes']),
                imageNote: null,
            );
        }

        /** @var ProductPromotionPayload $payload */
        $payload = $gate['payload'];

        if ($dryRun) {
            return new CatalogCandidatePromotionResult(
                promoted: true,
                productId: null,
                affiliateLinkId: null,
                exceptionCodes: [],
                error: null,
                imageNote: null,
                dryRun: true,
            );
        }

        $merchant = Merchant::query()->find($payload->merchantId);

        if (! $merchant instanceof Merchant) {
            return new CatalogCandidatePromotionResult(
                promoted: false,
                productId: null,
                affiliateLinkId: null,
                exceptionCodes: ['missing_merchant'],
                error: 'Promotion gate failed: missing_merchant',
                imageNote: null,
            );
        }

        $link = $this->upsertImportedProduct->execute($merchant, $payload->toImportedCatalogItem());
        $product = $link->product()->firstOrFail();

        $imageNote = null;

        if ($product->status === ProductStatus::Draft) {
            $imageNote = $this->storeImagesWhenPermitted($merchant, $product, $payload->imageUrls);
        }

        $this->applyTaxonomy->execute($product->fresh(), $payload->toTaxonomyClassification());

        $item->product_id = $link->product_id;
        $item->affiliate_link_id = $link->id;
        $item->save();

        $this->evaluateReadiness->execute($item->fresh());

        return new CatalogCandidatePromotionResult(
            promoted: true,
            productId: $link->product_id,
            affiliateLinkId: $link->id,
            exceptionCodes: [],
            error: null,
            imageNote: $imageNote,
        );
    }

    /**
     * @param  list<string>  $imageUrls
     */
    private function storeImagesWhenPermitted(Merchant $merchant, Product $product, array $imageUrls): ?string
    {
        if ($imageUrls === []) {
            return null;
        }

        $config = $this->merchants->configForSlug($merchant->slug) ?? [];
        $policyKey = (string) ($config['image_policy_key'] ?? 'fake');
        $policy = ProviderImagePolicy::forKey($policyKey);

        try {
            return $this->storeImportedProductImages->execute($product, $imageUrls, $policy);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return array{promotable: bool, codes: list<string>, payload?: ProductPromotionPayload}
     */
    private function validateGate(CatalogCandidateSourcingItem $item): array
    {
        $codes = [];

        if (! is_array($item->selected_offer) || $item->selected_offer === []) {
            $codes[] = 'missing_selected_offer';
        }

        if (! is_array($item->enrichment) || $item->enrichment === []) {
            $codes[] = 'missing_enrichment';

            return [
                'promotable' => false,
                'codes' => $codes,
            ];
        }

        try {
            $payload = ProductPromotionPayload::fromAuditArray($item->enrichment);
        } catch (InvalidArgumentException) {
            $codes[] = 'invalid_enrichment_snapshot';

            return [
                'promotable' => false,
                'codes' => $codes,
            ];
        }

        if (! $payload->affiliateReady) {
            $codes[] = 'affiliate_not_ready';
        }

        if (! is_string($payload->affiliateUrl) || ! filter_var($payload->affiliateUrl, FILTER_VALIDATE_URL)) {
            $codes[] = 'invalid_affiliate_url';
        }

        if (blank($payload->externalProductId)) {
            $codes[] = 'missing_external_product_id';
        }

        if ($payload->merchantId < 1) {
            $codes[] = 'missing_merchant';
        }

        if (trim($payload->name) === '') {
            $codes[] = 'missing_product_name';
        }

        if ($codes !== []) {
            return [
                'promotable' => false,
                'codes' => $codes,
            ];
        }

        return [
            'promotable' => true,
            'codes' => [],
            'payload' => $payload,
        ];
    }
}
