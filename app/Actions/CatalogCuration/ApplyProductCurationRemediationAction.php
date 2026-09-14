<?php

namespace App\Actions\CatalogCuration;

use App\Enums\ProductCurationDecision as ProductCurationDecisionValue;
use App\Models\ProductCurationDecision;

class ApplyProductCurationRemediationAction
{
    public function __construct(
        private DeactivateProductFromCurationAction $deactivate,
    ) {}

    public function execute(ProductCurationDecision $decision): ProductCurationDecision
    {
        return match ($decision->decision) {
            ProductCurationDecisionValue::Deactivate => $this->deactivate->execute($decision),
            default => $decision,
        };
    }
}
