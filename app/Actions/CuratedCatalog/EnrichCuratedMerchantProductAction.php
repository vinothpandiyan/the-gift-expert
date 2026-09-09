<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CuratedCatalog\CuratedClassificationConfidence;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductEnrichmentPrompt;
use App\CuratedCatalog\CuratedProductEnrichmentResult;
use App\CuratedCatalog\CuratedTaxonomyGap;
use App\Models\Merchant;
use App\Models\Relationship;

class EnrichCuratedMerchantProductAction
{
    public function __construct(
        private LoadActiveTaxonomyCatalogAction $loadTaxonomyCatalog,
        private CuratedProductEnrichmentPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
    ) {}

    /**
     * @param  list<int>  $relationshipHintIds
     */
    public function execute(
        Merchant $merchant,
        CuratedMerchantProductInput $input,
        array $relationshipHintIds = [],
        ?string $existingShortDescription = null,
        ?string $existingDescription = null,
    ): CuratedProductEnrichmentResult {
        $catalog = $this->loadTaxonomyCatalog->execute();
        $messages = $this->prompt->messages(
            $input,
            $merchant->name,
            $input->priceAmount,
            $input->priceCurrency,
            $catalog,
            $this->hintPayload($relationshipHintIds),
            $existingShortDescription,
            $existingDescription,
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
            $warnings[] = 'missing_primary_category';
        }

        $confidence = CuratedClassificationConfidence::fromArray(
            is_array($decoded['confidence'] ?? null) ? $decoded['confidence'] : [],
        );
        $taxonomyGap = CuratedTaxonomyGap::fromArray(
            is_array($decoded['taxonomy_gap'] ?? null) ? $decoded['taxonomy_gap'] : null,
        );
        $reasoning = is_array($decoded['reasoning_summary'] ?? null) ? $decoded['reasoning_summary'] : [];

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
                'relationship_hint_ids' => $relationshipHintIds,
            ],
            confidence: $confidence,
            reasoningSummary: $this->stringMap($reasoning),
            taxonomyGap: $taxonomyGap,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, slug: string, description: ?string}>
     */
    private function hintPayload(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Relationship::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description'])
            ->map(fn (Relationship $relationship): array => [
                'id' => (int) $relationship->id,
                'name' => (string) $relationship->name,
                'slug' => (string) $relationship->slug,
                'description' => is_string($relationship->description) ? $relationship->description : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, string>
     */
    private function stringMap(array $raw): array
    {
        $map = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
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
