<?php

namespace App\CatalogCandidate\Discovery;

interface CatalogCandidateSearchProvider
{
    public function search(CatalogCandidateResearchBrief $brief): CatalogCandidateSearchResult;
}
