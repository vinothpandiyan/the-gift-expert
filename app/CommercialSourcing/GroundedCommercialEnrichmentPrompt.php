<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;

class GroundedCommercialEnrichmentPrompt
{
    /**
     * @param  list<array{source_url: ?string, summary: ?string}>  $evidence
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        CatalogCandidate $candidate,
        SourcedMerchantOffer $offer,
        string $merchantName,
        ?string $extractedPriceAmount,
        ?string $extractedPriceCurrency,
        CommercialTaxonomyCatalog $catalog,
        array $evidence,
    ): array {
        return [
            'system' => $this->systemInstructions(),
            'user' => $this->userPayload(
                $candidate,
                $offer,
                $merchantName,
                $extractedPriceAmount,
                $extractedPriceCurrency,
                $catalog,
                $evidence,
            ),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(): string
    {
        return <<<'PROMPT'
You enrich one commercially sourced gift offer using only the supplied candidate, evidence, offer, and taxonomy catalog.

Write a customer-facing product name, optional short description, optional description, and optional brand. The name must represent the actual offer. Remove noisy listing and SEO text. Retain brand or model identity when the evidence contains it. Do not invent specifications, dimensions, materials, or features that are not supported by the supplied text.

Copy must be concise and grounded. Do not keyword-stuff. Do not mention popularity, rankings, or SEO.

Classify using taxonomy IDs from the catalog only. Never invent taxonomy names, slugs, or IDs.

Primary category must be a merchandising category (for example Home & Living or Electronics), not a recipient, relationship, or occasion leftover.

Gift types: do not tag ordinary physical products. Return Gifts, Digital / Instant Gifts, Gift Cards, Subscriptions, Online Courses, and E-books & Audiobooks apply only when the product semantics truly match. A normal physical marketplace gift usually has no gift type.

Professions: attach only when the item is genuinely profession-specific (for example a doctor-specific organizer). Do not tag generic mugs, lamps, or desk accessories as Software Developer, Business Owner, or similar because a professional might use them.

Relationships: only honest suitability from the candidate or offer context. Do not attach every relationship.

Recipient types: do not default Adult onto every product. Use Kids, Teen, Senior, Pet, or Couple only when useful.

Occasions: only supported broad occasions present in the evidence. Do not attach every celebration.

Do not output price, affiliate URLs, external product IDs, image URLs, merchant IDs, slugs, SEO metadata, publication status, or confidence percentages.

Output structured JSON only. No markdown.
PROMPT;
    }

    /**
     * @param  list<array{source_url: ?string, summary: ?string}>  $evidence
     */
    public function userPayload(
        CatalogCandidate $candidate,
        SourcedMerchantOffer $offer,
        string $merchantName,
        ?string $extractedPriceAmount,
        ?string $extractedPriceCurrency,
        CommercialTaxonomyCatalog $catalog,
        array $evidence,
    ): string {
        $payload = [
            'candidate' => [
                'title' => $candidate->title,
                'summary' => $candidate->summary,
            ],
            'evidence' => $evidence,
            'offer' => [
                'title' => $offer->title,
                'snippet' => $offer->snippet,
                'source_url' => $offer->sourceUrl,
                'merchant_name' => $merchantName,
            ],
            'extracted_price' => $extractedPriceAmount === null ? null : [
                'amount' => $extractedPriceAmount,
                'currency' => $extractedPriceCurrency,
                'note' => 'Context only. Do not echo, invent, or change the price.',
            ],
            'taxonomy_catalog' => $catalog->toPromptArray(),
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $idList = [
            'type' => 'array',
            'items' => ['type' => 'integer'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'short_description' => ['type' => ['string', 'null']],
                'description' => ['type' => ['string', 'null']],
                'brand' => ['type' => ['string', 'null']],
                'taxonomy' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'primary_category_id' => ['type' => ['integer', 'null']],
                        'category_ids' => $idList,
                        'occasion_ids' => $idList,
                        'relationship_ids' => $idList,
                        'recipient_type_ids' => $idList,
                        'interest_ids' => $idList,
                        'profession_ids' => $idList,
                        'gift_type_ids' => $idList,
                    ],
                    'required' => [
                        'primary_category_id',
                        'category_ids',
                        'occasion_ids',
                        'relationship_ids',
                        'recipient_type_ids',
                        'interest_ids',
                        'profession_ids',
                        'gift_type_ids',
                    ],
                ],
            ],
            'required' => ['name', 'short_description', 'description', 'brand', 'taxonomy'],
        ];
    }
}
