<?php

namespace App\CatalogCuration;

readonly class ProductCurationAuditReview
{
    /**
     * @param  list<string>  $intents
     * @param  list<string>  $strengths
     * @param  list<array{code: string, label: string, severity: string, message: string, context: list<array{label: string, value: string}>}>  $issues
     * @param  list<array{label: string, score: string, detail: ?string}>  $giftFactors
     * @param  list<array{label: string, score: string, detail: ?string}>  $catalogFactors
     * @param  list<array{label: string, count: int, ids: list<int>}>  $peers
     * @param  list<array{dimension: string, current: list<string>, audit: list<string>, differences: list<array{label: string, severity: string, forces_human_review: bool}>}>  $fitRows
     */
    public function __construct(
        public int $auditId,
        public string $completedAt,
        public ?int $giftScore,
        public ?int $catalogValueScore,
        public string $confidence,
        public string $recommendation,
        public bool $requiresHumanReview,
        public ?string $conceptKey,
        public ?string $conceptLabel,
        public string $catalogRole,
        public array $intents,
        public ?string $whyThisGift,
        public array $strengths,
        public array $issues,
        public array $giftFactors,
        public array $catalogFactors,
        public array $peers,
        public array $fitRows,
    ) {}
}
