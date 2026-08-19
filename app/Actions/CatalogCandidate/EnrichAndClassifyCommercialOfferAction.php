<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\Affiliate\ConfigAffiliateUrlBuilder;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ExtractCommercialOfferPrice;
use App\CommercialSourcing\GroundedCommercialEnrichmentPrompt;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CommercialSourcing\ProductPromotionPayload;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Models\CatalogCandidate;
use App\Models\Merchant;
use App\Support\CatalogCandidateSourceUrl;

class EnrichAndClassifyCommercialOfferAction
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
        private ConfigAffiliateUrlBuilder $affiliateUrlBuilder,
        private ExtractCommercialOfferPrice $extractPrice,
        private LoadActiveTaxonomyCatalogAction $loadTaxonomyCatalog,
        private GroundedCommercialEnrichmentPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private MapPriceToBudgetRangeAction $mapBudget,
    ) {}

    public function execute(
        CatalogCandidate $candidate,
        SourcedMerchantOffer $offer,
        ?int $sourcingItemId = null,
    ): ProductPromotionPayload {
        $config = $this->merchants->configForSlug($offer->merchantSlug) ?? [];
        $affiliate = $this->affiliateUrlBuilder->build($offer, $config);
        $price = $this->extractPrice->execute(trim($offer->title.' '.$offer->snippet));
        $catalog = $this->loadTaxonomyCatalog->execute();
        $evidence = $this->boundedEvidence($candidate);
        $merchantName = Merchant::query()->find($offer->merchantId)?->name ?? $offer->merchantSlug;

        $messages = $this->prompt->messages(
            $candidate,
            $offer,
            $merchantName,
            $price['amount'] ?? null,
            $price['currency'] ?? null,
            $catalog,
            $evidence,
        );

        $decoded = $this->client->complete($messages['system'], $messages['user'], $messages['schema']);
        $taxonomy = is_array($decoded['taxonomy'] ?? null) ? $decoded['taxonomy'] : [];
        $validated = $this->validateTaxonomy->execute($taxonomy);
        $budget = $this->mapBudget->execute($price['amount'] ?? null, $price['currency'] ?? null);

        $codes = $validated->exceptionCodes;

        if (! $affiliate->ready) {
            $codes[] = $affiliate->reasonCode ?? 'affiliate_manual';
        }

        if (($price['amount'] ?? null) === null) {
            $codes[] = 'missing_or_ambiguous_price';
        }

        $codes = array_values(array_unique($codes));

        $name = $this->nullableString($decoded['name'] ?? null) ?? $offer->title;

        if ($name === '') {
            throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
        }

        return new ProductPromotionPayload(
            catalogCandidateId: $candidate->id,
            sourcingItemId: $sourcingItemId,
            merchantId: $offer->merchantId,
            externalProductId: $offer->externalProductId,
            affiliateUrl: $affiliate->url,
            affiliateReady: $affiliate->ready,
            name: $name,
            shortDescription: $this->nullableString($decoded['short_description'] ?? null),
            description: $this->nullableString($decoded['description'] ?? null),
            brand: $this->nullableString($decoded['brand'] ?? null),
            priceAmount: $price['amount'] ?? null,
            priceCurrency: $price['currency'] ?? null,
            budgetRangeId: $budget?->id,
            primaryCategoryId: $validated->primaryCategoryId,
            categoryIds: $validated->categoryIds,
            occasionIds: $validated->occasionIds,
            relationshipIds: $validated->relationshipIds,
            recipientTypeIds: $validated->recipientTypeIds,
            interestIds: $validated->interestIds,
            professionIds: $validated->professionIds,
            giftTypeIds: $validated->giftTypeIds,
            imageUrls: $this->imageUrls($offer),
            exceptionCodes: $codes,
            enrichmentMetadata: [
                'affiliate_strategy' => $affiliate->strategy,
                'model' => config('commercial_sourcing.enrichment.model'),
                'enriched_at' => now()->toIso8601String(),
                'rejected_taxonomy_ids' => $validated->rejectedIds,
            ],
        );
    }

    /**
     * @return list<array{source_url: ?string, summary: ?string}>
     */
    private function boundedEvidence(CatalogCandidate $candidate): array
    {
        $maxEvidence = max(1, (int) config('commercial_sourcing.enrichment.max_evidence', 8));
        $snippetMax = max(1, (int) config('commercial_sourcing.enrichment.snippet_max_length', 400));
        $maxPromptChars = max(1, (int) config('commercial_sourcing.enrichment.max_prompt_chars', 24000));

        $evidence = [];

        foreach ($candidate->evidence()->limit($maxEvidence)->get() as $row) {
            $summary = is_string($row->summary) ? $row->summary : '';

            if (mb_strlen($summary) > $snippetMax) {
                $summary = mb_substr($summary, 0, $snippetMax);
            }

            $evidence[] = [
                'source_url' => $row->source_url,
                'summary' => $summary === '' ? null : $summary,
            ];
        }

        while ($evidence !== []) {
            $size = mb_strlen($this->prompt->systemInstructions()) + mb_strlen((string) json_encode($evidence));

            if ($size <= $maxPromptChars) {
                break;
            }

            array_pop($evidence);
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function imageUrls(SourcedMerchantOffer $offer): array
    {
        $urls = [];

        foreach ($offer->imageUrls as $url) {
            if (is_string($url) && CatalogCandidateSourceUrl::normalize($url) !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
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
