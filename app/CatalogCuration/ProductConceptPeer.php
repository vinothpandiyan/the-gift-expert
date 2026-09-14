<?php

namespace App\CatalogCuration;

use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision;

readonly class ProductConceptPeer
{
    /**
     * @param  list<string>  $giftIntents
     * @param  list<string>  $taxonomyContext
     */
    public function __construct(
        public int $productId,
        public string $title,
        public ?string $imageUrl,
        public ?string $price,
        public ?int $giftScore,
        public ?int $catalogValue,
        public ?int $differentiation,
        public ?string $budgetBand,
        public array $taxonomyContext,
        public array $giftIntents,
        public ?ProductCurationDecision $humanDecision,
        public ?CatalogRole $humanRole,
        public bool $isCurrent,
    ) {}
}
