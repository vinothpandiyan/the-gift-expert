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

Classify using taxonomy IDs from the catalog only. Never invent taxonomy names, slugs, or IDs.

Choose the most specific valid merchandising category available. Use broad roots such as Fashion & Accessories only when no better fit exists in the catalog.

Primary category: exactly 1 valid merchandising category (for example Home & Living or Electronics), not a recipient, relationship, or occasion leftover.

Additional categories: 0–2 when genuinely useful.

Relationships: target 2–4 honest useful fits when the product is broadly giftable. Do not attach every relationship.

Occasions: target 1–3 useful gifting occasions. Birthday commonly applies to general physical gifts. Anniversary only when reasonably appropriate. Do not attach every festival or celebration.

Interests: 0–3 only when meaningful.

Recipient types: 0–2 only when useful. Never default Adult onto every physical product.

Professions: normally 0 unless the item is genuinely occupation-specific.

Gift types: normally 0 for ordinary physical retail goods. Return Gifts, Digital / Instant Gifts, Gift Cards, Subscriptions, Online Courses, and E-books & Audiobooks apply only when the product semantics truly match.

A curation_group hint such as men or women is soft merchandising guidance only. Product semantics override it. A unisex item from a men's wishlist must not become male-exclusive merely because of the wishlist group.

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
                'curation_group_note' => 'Soft merchandising hint only. Guide relationship selection where sensible, but product semantics override gender or recipient assumptions.',
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
