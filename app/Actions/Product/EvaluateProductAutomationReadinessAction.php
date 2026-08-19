<?php

namespace App\Actions\Product;

use App\Actions\CatalogCandidate\FindCatalogCandidateProductOverlapAction;
use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ProductAutomationReadinessResult;
use App\CommercialSourcing\ProductPromotionPayload;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Import\ProviderImagePolicy;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\Merchant;
use App\Models\Product;
use InvalidArgumentException;

class EvaluateProductAutomationReadinessAction
{
    /** @var list<string> */
    private const BLOCKED_CODES = [
        'rejected',
        'under_review',
        'not_approved',
        'no_offer',
        'unstable_identity',
        'manual_identity',
        'missing_external_id',
        'missing_selected_offer',
        'missing_enrichment',
        'invalid_enrichment_snapshot',
        'affiliate_not_ready',
        'affiliate_manual',
        'invalid_affiliate_url',
        'missing_external_product_id',
        'missing_merchant',
        'missing_product_name',
        'no_product',
        'missing_name',
        'missing_slug',
        'no_image',
        'no_active_affiliate_link',
    ];

    /** @var list<string> */
    private const REVIEW_CODES = [
        'not_promoted',
        'missing_primary_category',
        'missing_or_ambiguous_price',
        'taxonomy_ids_rejected',
        'taxonomy_too_broad',
        'name_overlap',
        'incomplete_copy',
    ];

    /** @var list<string> */
    private const EXPLANATORY_CODES = [
        'image_policy',
        'image_acquisition_failed',
    ];

    public function __construct(
        private AssessProductPublicationRequirementsAction $assessPublication,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private FindCatalogCandidateProductOverlapAction $findProductOverlap,
        private CommercialSourcingMerchants $merchants,
    ) {}

    public function execute(CatalogCandidateSourcingItem $item): ProductAutomationReadinessResult
    {
        $item->loadMissing(['candidate', 'merchant', 'product', 'affiliateLink']);

        if ($item->product_id === null) {
            return $this->evaluatePrePromotion($item);
        }

        $product = $item->product;

        if (! $product instanceof Product) {
            return new ProductAutomationReadinessResult(
                readiness: ProductAutomationReadiness::Blocked,
                exceptionCodes: ['no_product'],
            );
        }

        if ($product->status !== ProductStatus::Draft) {
            return new ProductAutomationReadinessResult(
                readiness: null,
                exceptionCodes: [],
            );
        }

        return $this->evaluateDraftProduct($item, $product);
    }

    private function evaluatePrePromotion(CatalogCandidateSourcingItem $item): ProductAutomationReadinessResult
    {
        $codes = [];

        if ($item->status === CatalogCandidateSourcingItemStatus::Skipped
            || $item->status === CatalogCandidateSourcingItemStatus::Failed) {
            $codes = $this->mergeCodes($codes, is_array($item->exception_codes) ? $item->exception_codes : []);

            return $this->resultFromCodes($codes);
        }

        $gate = $this->promotionGateCodes($item);

        if ($gate !== []) {
            return $this->resultFromCodes($gate);
        }

        return $this->resultFromCodes(['not_promoted']);
    }

    private function evaluateDraftProduct(
        CatalogCandidateSourcingItem $item,
        Product $product,
    ): ProductAutomationReadinessResult {
        $product->loadMissing([
            'images',
            'affiliateLinks',
            'categories',
            'occasions',
            'relationships',
            'recipientTypes',
            'interests',
            'professions',
            'giftTypes',
        ]);

        $codes = [];
        $warnings = [];

        $publication = $this->assessPublication->execute($product);
        $codes = $this->mergeCodes($codes, $publication['error_codes']);

        foreach ($publication['warnings'] as $warning) {
            if (in_array($warning, self::REVIEW_CODES, true)) {
                $codes = $this->mergeCodes($codes, [$warning]);
            } else {
                $warnings[] = $warning;
            }
        }

        $codes = $this->mergeCodes($codes, $this->affiliateLinkCodes($product, $item));
        $codes = $this->mergeCodes($codes, $this->identityCodes($product));
        $codes = $this->mergeCodes($codes, $this->imageCodes($item, $product));
        $codes = $this->mergeCodes($codes, $this->taxonomyCodes($product));
        $codes = $this->mergeCodes($codes, $this->nameOverlapCodes($item, $product));
        $codes = $this->mergeCodes($codes, $this->incompleteCopyCodes($product));

        if ($product->images()->exists()) {
            $codes = array_values(array_diff($codes, ['no_image', 'image_policy', 'image_acquisition_failed']));
        }

        return $this->resultFromCodes($codes, $warnings);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function promotionGateCodes(CatalogCandidateSourcingItem $item): array
    {
        $codes = [];

        if (! is_array($item->selected_offer) || $item->selected_offer === []) {
            $codes[] = 'missing_selected_offer';
        }

        if (! is_array($item->enrichment) || $item->enrichment === []) {
            $codes[] = 'missing_enrichment';

            return $codes;
        }

        try {
            $payload = ProductPromotionPayload::fromAuditArray($item->enrichment);
        } catch (InvalidArgumentException) {
            $codes[] = 'invalid_enrichment_snapshot';

            return $codes;
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

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function affiliateLinkCodes(Product $product, CatalogCandidateSourcingItem $item): array
    {
        $codes = [];
        $activeLink = $product->affiliateLinks
            ->first(fn (AffiliateLink $link): bool => $link->status === AffiliateLinkStatus::Active);

        if ($activeLink === null) {
            return $codes;
        }

        if (! is_string($activeLink->url) || ! filter_var($activeLink->url, FILTER_VALIDATE_URL)) {
            $codes[] = 'invalid_affiliate_url';
        }

        if (blank($activeLink->external_product_id)) {
            $codes[] = 'missing_external_product_id';
        }

        if ($item->affiliate_link_id !== null && $activeLink->id !== $item->affiliate_link_id) {
            return $codes;
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function identityCodes(Product $product): array
    {
        $activeLink = $product->affiliateLinks
            ->first(fn (AffiliateLink $link): bool => $link->status === AffiliateLinkStatus::Active);

        if ($activeLink !== null && ! blank($activeLink->external_product_id)) {
            return [];
        }

        return ['missing_external_id'];
    }

    /**
     * @return list<string>
     */
    private function imageCodes(CatalogCandidateSourcingItem $item, Product $product): array
    {
        if (! config('gift_publication.requirements.image')) {
            return [];
        }

        if ($product->images()->exists()) {
            return [];
        }

        $codes = ['no_image'];

        if ($this->imagePolicyPreventedAcquisition($item)) {
            $codes[] = 'image_policy';
        } elseif ($this->imageAcquisitionWasAttempted($item)) {
            $codes[] = 'image_acquisition_failed';
        }

        return $codes;
    }

    private function imagePolicyPreventedAcquisition(CatalogCandidateSourcingItem $item): bool
    {
        if (! $this->hasImageUrls($item)) {
            return false;
        }

        $merchant = $item->merchant;

        if (! $merchant instanceof Merchant) {
            return false;
        }

        $config = $this->merchants->configForSlug($merchant->slug) ?? [];
        $policyKey = (string) ($config['image_policy_key'] ?? 'fake');
        $policy = ProviderImagePolicy::forKey($policyKey);

        return ! $policy->allowsLocalAcquisition();
    }

    private function imageAcquisitionWasAttempted(CatalogCandidateSourcingItem $item): bool
    {
        if (! $this->hasImageUrls($item)) {
            return false;
        }

        $merchant = $item->merchant;

        if (! $merchant instanceof Merchant) {
            return false;
        }

        $config = $this->merchants->configForSlug($merchant->slug) ?? [];
        $policyKey = (string) ($config['image_policy_key'] ?? 'fake');
        $policy = ProviderImagePolicy::forKey($policyKey);

        return $policy->allowsLocalAcquisition();
    }

    private function hasImageUrls(CatalogCandidateSourcingItem $item): bool
    {
        if (! is_array($item->enrichment)) {
            return false;
        }

        $urls = $item->enrichment['image_urls'] ?? [];

        if (! is_array($urls)) {
            return false;
        }

        foreach ($urls as $url) {
            if (is_string($url) && trim($url) !== '') {
                return true;
            }
        }

        if (is_array($item->selected_offer)) {
            $offerUrls = $item->selected_offer['image_urls'] ?? [];

            if (is_array($offerUrls)) {
                foreach ($offerUrls as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function taxonomyCodes(Product $product): array
    {
        $primaryCategoryId = $product->categories
            ->first(fn ($category): bool => (bool) $category->pivot->is_primary)?->id;

        $taxonomy = [
            'primary_category_id' => $primaryCategoryId,
            'category_ids' => $product->categories->pluck('id')->all(),
            'occasion_ids' => $product->occasions->pluck('id')->all(),
            'relationship_ids' => $product->relationships->pluck('id')->all(),
            'recipient_type_ids' => $product->recipientTypes->pluck('id')->all(),
            'interest_ids' => $product->interests->pluck('id')->all(),
            'profession_ids' => $product->professions->pluck('id')->all(),
            'gift_type_ids' => $product->giftTypes->pluck('id')->all(),
        ];

        $validated = $this->validateTaxonomy->execute($taxonomy);

        return $validated->exceptionCodes;
    }

    /**
     * @return list<string>
     */
    private function nameOverlapCodes(CatalogCandidateSourcingItem $item, Product $product): array
    {
        $candidate = $item->candidate;

        if (! $candidate instanceof CatalogCandidate) {
            return [];
        }

        $overlaps = $this->findProductOverlap->execute($candidate)
            ->reject(fn (Product $match): bool => $match->id === $product->id);

        return $overlaps->isNotEmpty() ? ['name_overlap'] : [];
    }

    /**
     * @return list<string>
     */
    private function incompleteCopyCodes(Product $product): array
    {
        if (blank($product->short_description) || blank($product->brand)) {
            return ['incomplete_copy'];
        }

        return [];
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $more
     * @return list<string>
     */
    private function mergeCodes(array $codes, array $more): array
    {
        foreach ($more as $code) {
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $warnings
     */
    private function resultFromCodes(array $codes, array $warnings = []): ProductAutomationReadinessResult
    {
        $severityCodes = array_values(array_diff($codes, self::EXPLANATORY_CODES));

        $hasBlocked = false;
        $hasReview = false;

        foreach ($severityCodes as $code) {
            if (in_array($code, self::BLOCKED_CODES, true)) {
                $hasBlocked = true;
            }

            if (in_array($code, self::REVIEW_CODES, true)) {
                $hasReview = true;
            }
        }

        $readiness = match (true) {
            $hasBlocked => ProductAutomationReadiness::Blocked,
            $hasReview => ProductAutomationReadiness::NeedsReview,
            default => ProductAutomationReadiness::Ready,
        };

        return new ProductAutomationReadinessResult(
            readiness: $readiness,
            exceptionCodes: $codes,
            warnings: $warnings,
        );
    }
}
