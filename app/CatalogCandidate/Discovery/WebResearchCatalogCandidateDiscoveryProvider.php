<?php

namespace App\CatalogCandidate\Discovery;

use App\Actions\CatalogCandidate\SearchCatalogCandidateSourcesAction;
use App\Actions\CatalogCandidate\SynthesizeCatalogCandidatesFromSourcesAction;

class WebResearchCatalogCandidateDiscoveryProvider implements CatalogCandidateDiscoveryProvider
{
    public function discover(CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryResult
    {
        $search = app(SearchCatalogCandidateSourcesAction::class)->execute($brief);

        if ($search->corpus === []) {
            return new CatalogCandidateDiscoveryResult(
                candidates: [],
                corpus: [],
                queries: $search->queries,
                metadata: array_merge($search->metadata, [
                    'provider' => 'web_research',
                    'synthesis' => 'skipped_empty_corpus',
                ]),
            );
        }

        return app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute($brief, $search);
    }
}
