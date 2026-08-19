<?php

namespace App\CommercialSourcing;

use App\Enums\CatalogCandidateSourcingItemStatus;

readonly class CatalogCandidateSourcingItemOutcome
{
    /**
     * @param  list<string>  $exceptionCodes
     * @param  array<string, int>  $rankBreakdown
     */
    public function __construct(
        public int $index,
        public int $candidateId,
        public string $candidateTitle,
        public CatalogCandidateSourcingItemStatus $status,
        public ?SourcedMerchantOffer $selected,
        public array $exceptionCodes,
        public array $rankBreakdown,
        public ?string $error,
        public ?ProductPromotionPayload $payload = null,
        public ?int $productId = null,
        public ?int $affiliateLinkId = null,
    ) {}
}
