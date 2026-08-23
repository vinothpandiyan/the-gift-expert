<?php

namespace App\CuratedCatalog;

use App\CommercialSourcing\CommercialTaxonomyCatalog;

class CuratedProductEnrichmentPrompt
{
    /**
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        CuratedMerchantProductInput $input,
        string $merchantName,
        ?string $priceAmount,
        ?string $priceCurrency,
        CommercialTaxonomyCatalog $catalog,
    ): array {
        return [
            'system' => $this->systemInstructions(),
            'user' => $this->userPayload($input, $merchantName, $priceAmount, $priceCurrency, $catalog),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(): string
    {
        return <<<'PROMPT'
You enrich one operator-curated merchant product using only the supplied product title, optional price context, optional curation hint, merchant name, source URL, and taxonomy catalog.

The operator already curated this SKU as gift-worthy. You are not deciding whether it is a gift. Your job is merchandising discovery mapping plus concise editorial copy: normalize the title, write factual short copy, write gift-oriented description, optional brand when clearly supported, and map useful taxonomy IDs.

Copy contract:
- name: concise catalog title; retain useful brand, model, or product type; strip marketplace keyword spam; no "Best Gift for..." patterns.
- short_description: what the item is; factual; about 100–180 characters when practical; no invented claims.
- description: why it works as a gift; 1–2 short paragraphs maximum; editorial and decision-helping; no fabricated specifications.

Never invent materials, quantities, warranty, technical performance, ratings, review counts, bestseller/trending/popularity claims, or features unless explicitly present in the supplied title.

Do not keyword-stuff. Do not mention popularity, rankings, or SEO.

Classify using taxonomy IDs from the catalog only. Never invent taxonomy names, slugs, or IDs. Use three distinct classification standards:

1. Product identity — Category answers "What is this?" Be precise.
2. Gift eligibility — Relationship and Occasion answer "For whom or when could this naturally and reasonably work as a gift?" Map every honest discovery context, not merely the single best recipient or occasion.
3. Intrinsic or specialized relevance — Interest, RecipientType, Profession, and GiftType describe characteristics the product actually represents. Be conservative.

Category rules:
- Return exactly 1 primary category and 0–2 additional categories.
- Choose the most specific valid merchandising category available. Use broad roots such as Fashion & Accessories only when no better fit exists in the catalog.
- Never attach a category merely to increase discovery coverage.
- Never use a recipient-, relationship-, occasion-, or SEO-intent-shaped category as primary.

Relationship rules:
- Return every active relationship for which this exact SKU would be a natural, reasonable gift. Do not stop at the strongest or most obvious recipient.
- Generic or unisex products should usually have broad-but-honest coverage across applicable male, female, and neutral relationships in the supplied catalog.
- Gender-specific products must remain gender-compatible. A men's wallet may fit several male relationships, but must not gain female relationships merely for coverage.
- Relationship-specific products must remain narrow. "Best Dad Ever" maps to Father, not to every relationship that could technically receive a mug.
- Assess each relationship independently. Never add a relationship when the gift would feel unnatural, forced, or semantically contradicted by the product.

Occasion rules:
- Use broader eligibility for general occasions such as Birthday, Housewarming, Festival, Christmas, New Year, or similar supplied catalog entries when the product is naturally useful or relevant.
- Require stronger semantic fit for specific or emotional occasions such as Anniversary, Wedding, Engagement, Raksha Bandhan, or Baby Shower.
- Do not reason that any product can technically be given for every occasion. Prefer honest omission over weak tags and avoid occasion saturation.
- Do not force an arbitrary 1–3 target when additional general occasions genuinely fit.

Interests: 0–3 only when there is a meaningful semantic match. Zero is valid; do not add weak interests for coverage.

Recipient types: 0–2 only when useful. Zero is valid. Never default Adult onto every physical product, and do not treat unisex as equivalent to Adult.

Professions: normally 0 unless the item is genuinely occupation-specific. General work or laptop use does not make a product profession-specific.

Gift types: normally 0 for ordinary physical retail goods. Return Gifts, Digital / Instant Gifts, Gift Cards, Subscriptions, Online Courses, and E-books & Audiobooks apply only when the product semantics truly match.

A curation_group hint such as men or women means only that the item was found while an operator curated that collection. It is soft merchandising context, not a recipient restriction. Product semantics override it. A generic unisex item from a men's wishlist must retain applicable female and neutral relationships; a men's wallet is male-compatible because of the product itself, not because of its curation group.

Do not output price, affiliate URLs, external product IDs, image URLs, merchant IDs, slugs, SEO metadata, publication status, or confidence percentages.

Output structured JSON only. No markdown.
PROMPT;
    }

    public function userPayload(
        CuratedMerchantProductInput $input,
        string $merchantName,
        ?string $priceAmount,
        ?string $priceCurrency,
        CommercialTaxonomyCatalog $catalog,
    ): string {
        $payload = [
            'curated_product' => [
                'title' => $input->title,
                'source_url' => $input->sourceUrl,
                'merchant_name' => $merchantName,
                'curation_group' => $input->curationGroup,
                'curation_group_note' => 'Soft operator context only: this is where the item was found, not a recipient restriction. Classify from product semantics; generic unisex products retain applicable male, female, and neutral relationships.',
            ],
            'extracted_price' => $priceAmount === null ? null : [
                'amount' => $priceAmount,
                'currency' => $priceCurrency,
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Concise catalog title without marketplace keyword spam.',
                ],
                'short_description' => [
                    'type' => ['string', 'null'],
                    'description' => 'Factual one-line description of what the item is.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => 'Short gift-oriented editorial paragraph explaining why it works as a gift.',
                ],
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
