<?php

namespace App\Actions\CuratedCatalog;

use App\Models\AffiliateLink;
use App\Models\Product;

class BuildCuratedRelationshipHintFingerprintAction
{
    public function __construct(
        private ResolveCatalogSourceRelationshipHintsAction $resolveHints,
    ) {}

    public function execute(AffiliateLink|Product $subject): string
    {
        $ids = $this->resolveHints->execute($subject);
        sort($ids);

        return hash('sha256', (string) json_encode(array_values($ids)));
    }
}
