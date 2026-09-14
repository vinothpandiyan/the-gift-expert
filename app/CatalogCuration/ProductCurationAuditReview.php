<?php

namespace App\CatalogCuration;

readonly class ProductCurationAuditReview
{
    /**
     * @param  list<string>  $intents
     * @param  list<string>  $strengths
     * @param  list<array{code: string, label: string, severity: string, message: string, context: list<array{label: string, value: string}>}>  $issues
     * @param  list<array{code: string, label: string, message: string, severity: string}>  $concerns
     * @param  list<array{label: string, score: string, points: int|string|null, max: int|string|null, detail: ?string}>  $giftFactors
     * @param  list<array{label: string, score: string, points: int|string|null, max: int|string|null, detail: ?string}>  $catalogFactors
     * @param  list<array{label: string, count: int, ids: list<int>}>  $peers
     * @param  list<array{dimension: string, current: list<string>, audit: list<string>, currentItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, auditItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, differences: list<array{label: string, action: string, action_label: string, name: string, severity: string, forces_human_review: bool}>}>  $fitRows
     * @param  list<array{label: string, value: string}>  $catalogContext
     * @param  list<array{label: string, value: string}>  $metadata
     */
    public function __construct(
        public int $auditId,
        public string $completedAt,
        public ?int $giftScore,
        public ?int $catalogValueScore,
        public ?string $giftScoreBand,
        public ?string $catalogValueBand,
        public string $confidence,
        public string $confidenceColor,
        public string $recommendation,
        public string $recommendationColor,
        public bool $requiresHumanReview,
        public ?string $conceptKey,
        public ?string $conceptLabel,
        public string $catalogRole,
        public array $intents,
        public ?string $whyThisGift,
        public array $strengths,
        public array $issues,
        public array $concerns,
        public array $giftFactors,
        public array $catalogFactors,
        public array $peers,
        public array $fitRows,
        public array $catalogContext,
        public array $metadata,
    ) {}
}
