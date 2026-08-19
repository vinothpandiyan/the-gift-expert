<?php

namespace App\Actions\Product;

use App\Models\CatalogCandidateSourcingItem;

class EvaluateAndPersistProductAutomationReadinessAction
{
    public function __construct(
        private EvaluateProductAutomationReadinessAction $evaluate,
    ) {}

    public function execute(CatalogCandidateSourcingItem $item): CatalogCandidateSourcingItem
    {
        $result = $this->evaluate->execute($item);

        $enrichment = is_array($item->enrichment) ? $item->enrichment : [];
        $metadata = is_array($enrichment['metadata'] ?? null) ? $enrichment['metadata'] : [];
        $metadata['readiness_evaluated_at'] = now()->toIso8601String();
        $enrichment['metadata'] = $metadata;

        $item->readiness = $result->readiness?->value;
        $item->exception_codes = $result->exceptionCodes === [] ? null : $result->exceptionCodes;
        $item->enrichment = $enrichment;
        $item->save();

        return $item->fresh();
    }
}
