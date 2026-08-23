<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakePreview
{
    /**
     * @param  list<CuratedProductIntakePreviewItem>  $items
     */
    public function __construct(
        public string $merchantSlug,
        public int $itemsTotal,
        public int $itemsValid,
        public int $itemsInvalid,
        public int $itemsNew,
        public int $itemsExisting,
        public int $itemsDuplicate,
        public int $itemsTrashed,
        public int $itemsMissingPrice,
        public int $itemsMissingImage,
        public int $itemsUnavailable,
        public int $itemsAffiliateNotReady,
        public int $itemsActionable,
        public array $items,
    ) {}

    /**
     * @return list<CuratedProductIntakePreviewItem>
     */
    public function actionableItems(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (CuratedProductIntakePreviewItem $item): bool => in_array($item->proposedAction, ['CREATE', 'UPDATE'], true),
        ));
    }
}
