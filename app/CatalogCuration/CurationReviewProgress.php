<?php

namespace App\CatalogCuration;

use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationPriority;

readonly class CurationReviewProgress
{
    /**
     * @param  array<string, int>  $decisionCounts
     * @param  array<string, int>  $priorityCounts
     * @param  array<string, int>  $p3SubgroupCounts
     */
    public function __construct(
        public int $mandatoryReview,
        public int $decided,
        public int $deferred,
        public int $remaining,
        public array $decisionCounts,
        public array $priorityCounts,
        public int $featureCount = 0,
        public int $keepFamilyCount = 0,
        public float $featureShare = 0.0,
        public bool $featureDensityWarning = false,
        public array $p3SubgroupCounts = [],
    ) {}

    public function countFor(ProductCurationDecision $decision): int
    {
        return $this->decisionCounts[$decision->value] ?? 0;
    }

    public function countForPriority(ProductCurationPriority $priority): int
    {
        return $this->priorityCounts[$priority->value] ?? 0;
    }
}
