<?php

namespace App\Actions\CatalogCuration;

use App\Enums\CurationAiConfidence;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationPriority;
use App\Models\ProductCurationAudit;

class ResolveP3QualitySubgroupAction
{
    public function __construct(
        private ResolveProductCurationPriorityAction $priority,
    ) {}

    public function execute(ProductCurationAudit $audit): ?P3QualitySubgroup
    {
        if ($this->priority->execute($audit) !== ProductCurationPriority::P3) {
            return null;
        }

        if ($this->hasLowGiftScore($audit)) {
            return P3QualitySubgroup::LowGiftScore;
        }

        if ($this->hasLowCatalogValue($audit)) {
            return P3QualitySubgroup::LowCatalogValue;
        }

        if ($this->priority->hasEvidenceIssue($audit)) {
            return P3QualitySubgroup::WeakEvidence;
        }

        if ($audit->ai_confidence === CurationAiConfidence::Low) {
            return P3QualitySubgroup::LowConfidence;
        }

        return P3QualitySubgroup::RemainingQuality;
    }

    public function hasLowGiftScore(ProductCurationAudit $audit): bool
    {
        return $audit->gift_score !== null
            && $audit->gift_score < (int) config('catalog_curation.thresholds.human_review_gift_score', 65);
    }

    public function hasLowCatalogValue(ProductCurationAudit $audit): bool
    {
        return $audit->catalog_value_score !== null
            && $audit->catalog_value_score < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50);
    }
}
