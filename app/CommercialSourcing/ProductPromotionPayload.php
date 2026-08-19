<?php

namespace App\CommercialSourcing;

readonly class ProductPromotionPayload
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $occasionIds
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $recipientTypeIds
     * @param  list<int>  $interestIds
     * @param  list<int>  $professionIds
     * @param  list<int>  $giftTypeIds
     * @param  list<string>  $imageUrls
     * @param  list<string>  $exceptionCodes
     * @param  array<string, mixed>  $enrichmentMetadata
     */
    public function __construct(
        public int $catalogCandidateId,
        public ?int $sourcingItemId,
        public int $merchantId,
        public ?string $externalProductId,
        public ?string $affiliateUrl,
        public bool $affiliateReady,
        public string $name,
        public ?string $shortDescription,
        public ?string $description,
        public ?string $brand,
        public ?string $priceAmount,
        public ?string $priceCurrency,
        public ?int $budgetRangeId,
        public ?int $primaryCategoryId,
        public array $categoryIds,
        public array $occasionIds,
        public array $relationshipIds,
        public array $recipientTypeIds,
        public array $interestIds,
        public array $professionIds,
        public array $giftTypeIds,
        public array $imageUrls,
        public array $exceptionCodes,
        public array $enrichmentMetadata,
    ) {}

    public function withSourcingItemId(int $sourcingItemId): self
    {
        return new self(
            catalogCandidateId: $this->catalogCandidateId,
            sourcingItemId: $sourcingItemId,
            merchantId: $this->merchantId,
            externalProductId: $this->externalProductId,
            affiliateUrl: $this->affiliateUrl,
            affiliateReady: $this->affiliateReady,
            name: $this->name,
            shortDescription: $this->shortDescription,
            description: $this->description,
            brand: $this->brand,
            priceAmount: $this->priceAmount,
            priceCurrency: $this->priceCurrency,
            budgetRangeId: $this->budgetRangeId,
            primaryCategoryId: $this->primaryCategoryId,
            categoryIds: $this->categoryIds,
            occasionIds: $this->occasionIds,
            relationshipIds: $this->relationshipIds,
            recipientTypeIds: $this->recipientTypeIds,
            interestIds: $this->interestIds,
            professionIds: $this->professionIds,
            giftTypeIds: $this->giftTypeIds,
            imageUrls: $this->imageUrls,
            exceptionCodes: $this->exceptionCodes,
            enrichmentMetadata: $this->enrichmentMetadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'catalog_candidate_id' => $this->catalogCandidateId,
            'sourcing_item_id' => $this->sourcingItemId,
            'merchant_id' => $this->merchantId,
            'external_product_id' => $this->externalProductId,
            'affiliate_url' => $this->affiliateUrl,
            'affiliate_ready' => $this->affiliateReady,
            'name' => $this->name,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'brand' => $this->brand,
            'price_amount' => $this->priceAmount,
            'price_currency' => $this->priceCurrency,
            'budget_range_id' => $this->budgetRangeId,
            'primary_category_id' => $this->primaryCategoryId,
            'taxonomy' => [
                'category_ids' => $this->categoryIds,
                'occasion_ids' => $this->occasionIds,
                'relationship_ids' => $this->relationshipIds,
                'recipient_type_ids' => $this->recipientTypeIds,
                'interest_ids' => $this->interestIds,
                'profession_ids' => $this->professionIds,
                'gift_type_ids' => $this->giftTypeIds,
            ],
            'image_urls' => $this->imageUrls,
            'exception_codes' => $this->exceptionCodes,
            'metadata' => $this->enrichmentMetadata,
        ];
    }
}
