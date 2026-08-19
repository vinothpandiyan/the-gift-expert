<?php

namespace App\CommercialSourcing;

use App\Import\ImportedCatalogItem;
use InvalidArgumentException;

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
    /**
     * @param  array<string, mixed>  $audit
     */
    public static function fromAuditArray(array $audit): self
    {
        $taxonomy = is_array($audit['taxonomy'] ?? null) ? $audit['taxonomy'] : [];

        return new self(
            catalogCandidateId: (int) ($audit['catalog_candidate_id'] ?? 0),
            sourcingItemId: self::nullableInt($audit['sourcing_item_id'] ?? null),
            merchantId: (int) ($audit['merchant_id'] ?? 0),
            externalProductId: self::nullableString($audit['external_product_id'] ?? null),
            affiliateUrl: self::nullableString($audit['affiliate_url'] ?? null),
            affiliateReady: (bool) ($audit['affiliate_ready'] ?? false),
            name: (string) ($audit['name'] ?? ''),
            shortDescription: self::nullableString($audit['short_description'] ?? null),
            description: self::nullableString($audit['description'] ?? null),
            brand: self::nullableString($audit['brand'] ?? null),
            priceAmount: self::nullablePrice($audit['price_amount'] ?? null),
            priceCurrency: self::nullableString($audit['price_currency'] ?? null),
            budgetRangeId: self::nullableInt($audit['budget_range_id'] ?? null),
            primaryCategoryId: self::nullableInt($audit['primary_category_id'] ?? null),
            categoryIds: self::intList($taxonomy['category_ids'] ?? []),
            occasionIds: self::intList($taxonomy['occasion_ids'] ?? []),
            relationshipIds: self::intList($taxonomy['relationship_ids'] ?? []),
            recipientTypeIds: self::intList($taxonomy['recipient_type_ids'] ?? []),
            interestIds: self::intList($taxonomy['interest_ids'] ?? []),
            professionIds: self::intList($taxonomy['profession_ids'] ?? []),
            giftTypeIds: self::intList($taxonomy['gift_type_ids'] ?? []),
            imageUrls: self::stringList($audit['image_urls'] ?? []),
            exceptionCodes: self::stringList($audit['exception_codes'] ?? []),
            enrichmentMetadata: is_array($audit['metadata'] ?? null) ? $audit['metadata'] : [],
        );
    }

    public function toImportedCatalogItem(): ImportedCatalogItem
    {
        if (blank($this->externalProductId) || blank($this->affiliateUrl) || trim($this->name) === '') {
            throw new InvalidArgumentException('Promotion payload is missing required import fields.');
        }

        return new ImportedCatalogItem(
            name: $this->name,
            description: $this->description,
            short_description: $this->shortDescription,
            brand: $this->brand,
            price_amount: $this->priceAmount,
            price_currency: $this->priceCurrency,
            affiliate_url: $this->affiliateUrl,
            external_product_id: $this->externalProductId,
            image_urls: $this->imageUrls,
            raw: $this->toAuditArray(),
        );
    }

    public function toTaxonomyClassification(): ValidatedProductTaxonomyClassification
    {
        return new ValidatedProductTaxonomyClassification(
            primaryCategoryId: $this->primaryCategoryId,
            categoryIds: $this->categoryIds,
            occasionIds: $this->occasionIds,
            relationshipIds: $this->relationshipIds,
            recipientTypeIds: $this->recipientTypeIds,
            interestIds: $this->interestIds,
            professionIds: $this->professionIds,
            giftTypeIds: $this->giftTypeIds,
            exceptionCodes: [],
            rejectedIds: [],
        );
    }

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

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function nullablePrice(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $string = self::nullableString($value);

        if ($string === null || ! is_numeric($string)) {
            return $string;
        }

        return number_format((float) $string, 2, '.', '');
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = self::nullableInt($value);

            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $value) {
            $string = self::nullableString($value);

            if ($string !== null) {
                $values[] = $string;
            }
        }

        return $values;
    }
}
