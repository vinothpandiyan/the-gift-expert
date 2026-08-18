<?php

namespace App\CatalogCandidate\Discovery;

interface CatalogCandidateDiscoveryProvider
{
    public function discover(CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryResult;
}
