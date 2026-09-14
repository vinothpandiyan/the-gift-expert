<?php

namespace App\CatalogCuration;

readonly class HumanTaxonomyProposal
{
    /**
     * @param  array<string, list<int>>  $add
     * @param  array<string, list<int>>  $remove
     */
    public function __construct(
        public ?int $categoryFrom,
        public ?int $categoryTo,
        public array $add,
        public array $remove,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $add = [];
        $remove = [];

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $add[$dimension] = self::uniqueIds($data['add'][$dimension] ?? $data[$dimension]['add'] ?? []);
            $remove[$dimension] = self::uniqueIds($data['remove'][$dimension] ?? $data[$dimension]['remove'] ?? []);
        }

        $category = is_array($data['category'] ?? null) ? $data['category'] : [];

        return new self(
            categoryFrom: self::nullableId($category['from'] ?? $data['category_from'] ?? null),
            categoryTo: self::nullableId($category['to'] ?? $data['category_to'] ?? null),
            add: $add,
            remove: $remove,
        );
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function fromForm(array $form, ?int $currentPrimaryCategoryId): self
    {
        $selectedPrimary = self::nullableId($form['taxonomy_primary_category_id'] ?? null);
        $categoryChanged = $selectedPrimary !== null && $selectedPrimary !== $currentPrimaryCategoryId;

        $add = [];
        $remove = [];

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $add[$dimension] = self::uniqueIds($form['taxonomy_'.$dimension.'_add'] ?? []);
            $remove[$dimension] = self::uniqueIds($form['taxonomy_'.$dimension.'_remove'] ?? []);
        }

        return new self(
            categoryFrom: $categoryChanged ? $currentPrimaryCategoryId : null,
            categoryTo: $categoryChanged ? $selectedPrimary : null,
            add: $add,
            remove: $remove,
        );
    }

    public function hasChange(): bool
    {
        if ($this->categoryTo !== null && $this->categoryTo !== $this->categoryFrom) {
            return true;
        }

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            if (($this->add[$dimension] ?? []) !== [] || ($this->remove[$dimension] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    public function applyTo(ProductTaxonomySnapshot $snapshot): ProductTaxonomySnapshot
    {
        $names = $snapshot->names;
        $ids = [];

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $current = $snapshot->idsFor($dimension);
            $removed = $this->remove[$dimension] ?? [];
            $added = $this->add[$dimension] ?? [];
            $ids[$dimension] = array_values(array_unique([
                ...array_values(array_filter(
                    $current,
                    fn (int $id): bool => ! in_array($id, $removed, true),
                )),
                ...$added,
            ]));
        }

        $primary = $this->categoryTo ?? $snapshot->primaryCategoryId;
        $categoryIds = $snapshot->categoryIds;

        if ($this->categoryTo !== null && $this->categoryTo !== $snapshot->primaryCategoryId) {
            $categoryIds = [$this->categoryTo];
        }

        return new ProductTaxonomySnapshot(
            primaryCategoryId: $primary,
            categoryIds: $categoryIds,
            relationshipIds: $ids['relationships'],
            occasionIds: $ids['occasions'],
            interestIds: $ids['interests'],
            giftTypeIds: $ids['gift_types'],
            recipientTypeIds: $ids['recipient_types'],
            recipientGenderIds: $ids['recipient_genders'],
            professionIds: $ids['professions'],
            classificationStatus: $snapshot->classificationStatus,
            names: $names,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'category' => [
                'from' => $this->categoryFrom,
                'to' => $this->categoryTo,
            ],
        ];

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $payload[$dimension] = [
                'add' => $this->add[$dimension] ?? [],
                'remove' => $this->remove[$dimension] ?? [],
            ];
        }

        return $payload;
    }

    /**
     * @return list<int>
     */
    private static function uniqueIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): ?int => self::nullableId($value), $values),
            fn (?int $id): bool => $id !== null,
        )));
    }

    private static function nullableId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
