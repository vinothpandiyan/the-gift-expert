<?php

namespace App\Actions\CatalogCuration;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\CatalogCuration\ProductCurationEvidence;
use App\CatalogCuration\ProductCurationSemanticPrompt;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;

class EvaluateProductCurationSemanticsAction
{
    public function __construct(
        private LoadActiveTaxonomyCatalogAction $loadTaxonomyCatalog,
        private ProductCurationSemanticPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
        private ValidateCurationSemanticEvaluationAction $validate,
    ) {}

    /**
     * @return array{evaluation: array<string, mixed>, issues: list<array<string, mixed>>}
     */
    public function execute(ProductCurationEvidence $evidence): array
    {
        $catalog = $this->loadTaxonomyCatalog->execute();
        $messages = $this->prompt->messages($evidence, $catalog);
        $response = $this->client->complete(
            $messages['system'],
            $messages['user'],
            $messages['schema'],
            requireTaxonomy: false,
        );

        return $this->validate->execute($response, $evidence, $catalog);
    }
}
