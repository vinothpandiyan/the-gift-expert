<?php

namespace App\Actions\CatalogCuration;

use App\Models\ProductCurationAudit;

class CalculateCatalogCurationContextAction
{
    public function __construct(
        private CalculateRelativeCatalogCurationContextAction $relativeContext,
    ) {}

    /**
     * @return array{
     *   score: int,
     *   factors: array<string, int>,
     *   peer_product_ids: array<string, list<int>>,
     *   peer_counts: array<string, int>,
     *   snapshot: array<string, mixed>,
     *   fingerprint: string
     * }
     */
    public function execute(ProductCurationAudit $audit, bool $preferCurrentRun = true): array
    {
        return $this->relativeContext->execute($audit, $preferCurrentRun);
    }
}
