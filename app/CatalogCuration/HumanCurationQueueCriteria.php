<?php

namespace App\CatalogCuration;

use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationPriority;
use App\Enums\ProductStatus;

readonly class HumanCurationQueueCriteria
{
    public function __construct(
        public string $view = 'needs_review',
        public ?ProductCurationPriority $priority = null,
        public ?bool $requiresHumanReview = null,
        public ?ProductCurationDecision $humanDecision = null,
        public ?CurationRecommendation $recommendation = null,
        public ?int $giftScoreMin = null,
        public ?int $giftScoreMax = null,
        public ?int $catalogValueMin = null,
        public ?int $catalogValueMax = null,
        public ?string $conceptKey = null,
        public ?bool $hasConceptPeers = null,
        public ?int $relationshipId = null,
        public ?int $occasionId = null,
        public ?int $interestId = null,
        public ?int $giftTypeId = null,
        public ?string $budgetBand = null,
        public ?string $giftIntent = null,
        public ?string $taxonomySeverity = null,
        public ?bool $hasEvidenceIssue = null,
        public ?CurationAiConfidence $aiConfidence = null,
        public ?ProductStatus $status = null,
        public ?P3QualitySubgroup $qualitySubgroup = null,
        public string $sort = 'priority',
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters, string $view = 'needs_review', string $sort = 'priority'): self
    {
        $value = fn (string $key): mixed => $filters[$key] ?? null;

        return new self(
            view: $view,
            priority: self::enum($value('priority'), ProductCurationPriority::class),
            requiresHumanReview: self::bool($value('requires_human_review')),
            humanDecision: self::enum($value('human_decision'), ProductCurationDecision::class),
            recommendation: self::enum($value('recommendation'), CurationRecommendation::class),
            giftScoreMin: self::int($value('gift_score_min')),
            giftScoreMax: self::int($value('gift_score_max')),
            catalogValueMin: self::int($value('catalog_value_min')),
            catalogValueMax: self::int($value('catalog_value_max')),
            conceptKey: self::string($value('concept')),
            hasConceptPeers: self::bool($value('has_concept_peers')),
            relationshipId: self::int($value('relationship_id')),
            occasionId: self::int($value('occasion_id')),
            interestId: self::int($value('interest_id')),
            giftTypeId: self::int($value('gift_type_id')),
            budgetBand: self::string($value('budget_band')),
            giftIntent: self::string($value('gift_intent')),
            taxonomySeverity: self::string($value('taxonomy_severity')),
            hasEvidenceIssue: self::bool($value('evidence_issue')),
            aiConfidence: self::enum($value('ai_confidence'), CurationAiConfidence::class),
            status: self::enum($value('status'), ProductStatus::class),
            qualitySubgroup: self::enum($value('quality_subgroup'), P3QualitySubgroup::class),
            sort: $sort !== '' ? $sort : 'priority',
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(mixed $value, string $enum): mixed
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) && $value !== '' ? $enum::tryFrom($value) : null;
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === '1' || $value === 1 || $value === 'true') {
            return true;
        }

        if ($value === '0' || $value === 0 || $value === 'false') {
            return false;
        }

        return null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
