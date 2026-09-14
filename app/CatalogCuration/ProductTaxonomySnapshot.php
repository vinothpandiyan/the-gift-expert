<?php

namespace App\CatalogCuration;

readonly class ProductTaxonomySnapshot
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $occasionIds
     * @param  list<int>  $interestIds
     * @param  list<int>  $giftTypeIds
     * @param  list<int>  $recipientTypeIds
     * @param  list<int>  $recipientGenderIds
     * @param  list<int>  $professionIds
     * @param  array<string, array<int, string>>  $names
     */
    public function __construct(
        public ?int $primaryCategoryId,
        public array $categoryIds,
        public array $relationshipIds,
        public array $occasionIds,
        public array $interestIds,
        public array $giftTypeIds,
        public array $recipientTypeIds,
        public array $recipientGenderIds,
        public array $professionIds,
        public ?string $classificationStatus,
        public array $names,
    ) {}

    /**
     * @return list<string>
     */
    public static function dimensionKeys(): array
    {
        return [
            'relationships',
            'occasions',
            'interests',
            'gift_types',
            'recipient_types',
            'recipient_genders',
            'professions',
        ];
    }

    /**
     * @return list<int>
     */
    public function idsFor(string $dimension): array
    {
        return match ($dimension) {
            'relationships' => $this->relationshipIds,
            'occasions' => $this->occasionIds,
            'interests' => $this->interestIds,
            'gift_types' => $this->giftTypeIds,
            'recipient_types' => $this->recipientTypeIds,
            'recipient_genders' => $this->recipientGenderIds,
            'professions' => $this->professionIds,
            'categories' => $this->categoryIds,
            default => [],
        };
    }

    public function name(string $dimension, int $id): string
    {
        return $this->names[$dimension][$id] ?? '#'.$id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_category_id' => $this->primaryCategoryId,
            'category_ids' => $this->categoryIds,
            'relationship_ids' => $this->relationshipIds,
            'occasion_ids' => $this->occasionIds,
            'interest_ids' => $this->interestIds,
            'gift_type_ids' => $this->giftTypeIds,
            'recipient_type_ids' => $this->recipientTypeIds,
            'recipient_gender_ids' => $this->recipientGenderIds,
            'profession_ids' => $this->professionIds,
            'classification_status' => $this->classificationStatus,
            'names' => $this->names,
        ];
    }
}
