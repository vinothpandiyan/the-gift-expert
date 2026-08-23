<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductEnrichmentPrompt;
use App\CuratedCatalog\CuratedProductEnrichmentResult;
use App\Models\Merchant;

class EnrichCuratedMerchantProductAction
{
    public function __construct(
        private LoadActiveTaxonomyCatalogAction $loadTaxonomyCatalog,
        private CuratedProductEnrichmentPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
    ) {}

    public function execute(Merchant $merchant, CuratedMerchantProductInput $input): CuratedProductEnrichmentResult
    {
        $catalog = $this->loadTaxonomyCatalog->execute();
        $messages = $this->prompt->messages(
            $input,
            $merchant->name,
            $input->priceAmount,
            $input->priceCurrency,
            $catalog,
        );

        $decoded = $this->client->complete($messages['system'], $messages['user'], $messages['schema']);
        $taxonomy = is_array($decoded['taxonomy'] ?? null) ? $decoded['taxonomy'] : [];
        $configuredCaps = config('commercial_sourcing.curated_taxonomy_caps', []);
        $validated = $this->validateTaxonomy->execute(
            $taxonomy,
            is_array($configuredCaps) ? $configuredCaps : [],
        );

        $warnings = [];

        if ($validated->rejectedIds !== []) {
            $warnings[] = 'taxonomy_ids_rejected';
        }

        if (in_array('taxonomy_too_broad', $validated->exceptionCodes, true)) {
            $warnings[] = 'taxonomy_too_broad';
        }

        if ($validated->relationshipIds === []) {
            $warnings[] = 'missing_relationships';
        }

        if ($validated->occasionIds === []) {
            $warnings[] = 'missing_occasions';
        }

        if ($validated->interestIds === []) {
            $warnings[] = 'missing_interests';
        }

        if ($validated->recipientTypeIds === []) {
            $warnings[] = 'missing_recipient_types';
        }

        $name = $this->nullableString($decoded['name'] ?? null) ?? $input->title;

        if ($name === '') {
            throw new CommercialEnrichmentException('The curated enrichment response was malformed.');
        }

        if (in_array('missing_primary_category', $validated->exceptionCodes, true)) {
            throw new CommercialEnrichmentException('Curated enrichment did not produce a valid primary category.');
        }

        return new CuratedProductEnrichmentResult(
            name: $name,
            shortDescription: $this->nullableString($decoded['short_description'] ?? null),
            description: $this->nullableString($decoded['description'] ?? null),
            brand: $this->nullableString($decoded['brand'] ?? null),
            taxonomy: $validated,
            warnings: $warnings,
            metadata: [
                'model' => config('commercial_sourcing.enrichment.model'),
                'enriched_at' => now()->toIso8601String(),
                'rejected_taxonomy_ids' => $validated->rejectedIds,
            ],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
