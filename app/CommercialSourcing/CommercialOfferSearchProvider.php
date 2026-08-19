<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;

interface CommercialOfferSearchProvider
{
    public function search(CatalogCandidate $candidate, string $market): CommercialOfferSearchResult;
}
