<?php

namespace App\Actions\CatalogCuration;

use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationRemediationStatus;

class ResolveCurationRemediationStatusAction
{
    public function execute(ProductCurationDecision $decision): ProductCurationRemediationStatus
    {
        return $decision->requiresRemediation()
            ? ProductCurationRemediationStatus::Pending
            : ProductCurationRemediationStatus::NotRequired;
    }
}
