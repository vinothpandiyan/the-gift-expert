<?php

namespace App\CuratedCatalog;

use App\CommercialSourcing\CommercialTaxonomyCatalog;

class CuratedProductEnrichmentPrompt
{
    /**
     * @param  list<array{id: int, name: string, slug: string, description: ?string}>  $relationshipHints
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        CuratedMerchantProductInput $input,
        string $merchantName,
        ?string $priceAmount,
        ?string $priceCurrency,
        CommercialTaxonomyCatalog $catalog,
        array $relationshipHints = [],
        ?string $existingShortDescription = null,
        ?string $existingDescription = null,
    ): array {
        return [
            'system' => $this->systemInstructions(),
            'user' => $this->userPayload(
                $input,
                $merchantName,
                $priceAmount,
                $priceCurrency,
                $catalog,
                $relationshipHints,
                $existingShortDescription,
                $existingDescription,
            ),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(): string
    {
        return <<<'PROMPT'
You enrich one unique curated merchant product after provenance merge. Classify this SKU once using the supplied title, optional existing description/features, merchant, source URL, taxonomy catalog, and all trusted Relationship hints. Do not classify per wishlist.

The operator already curated this SKU as gift-worthy. You are not deciding whether it is a gift. Your job is merchandising discovery mapping plus concise editorial copy: normalize the title, write factual short copy, write gift-oriented description, optional brand when clearly supported, and map useful taxonomy IDs.

Copy contract:
- name: concise Gift Expert catalog title. Omit marketplace seller/brand prefixes when they add no meaningful product identity. Retain a brand only when that brand is itself part of how shoppers recognize the product. Strip marketplace keyword stuffing, repeated recipient/occasion phrases, pack/variant noise, and "Best Gift for..." patterns. Keep the actual product identity. Never invent attributes.
- short_description: what the item is; factual; about 100–180 characters when practical; no invented claims.
- description: why it is a great gift. Write 2–4 concise reasons, normally 3, each on its own line. No paragraph. No numbering, bullets, or leading checkmarks. Each line must be a distinct reason. Prioritize emotional or personal value, usefulness, or distinctive gift qualities. Do not repeat taxonomy labels verbatim. Avoid generic filler such as "Makes a wonderful gift". Do not make unsupported product claims.

Never invent materials, quantities, warranty, technical performance, ratings, review counts, bestseller/trending/popularity claims, or features unless explicitly present in the supplied title or description.

Do not keyword-stuff. Do not mention popularity, rankings, or SEO.

Classify using taxonomy IDs from the catalog only. Never invent taxonomy names, slugs, or IDs. Use three distinct classification standards:

1. Product identity — Category answers "What is this?" Be precise.
2. Gift eligibility — Relationship answers "Which recipients are particularly plausible gift targets for this product?" Occasion answers "When would this naturally work as a gift?" Relationship must be selective. Occasion follows the conservative Occasion rules below. Do not enumerate every person who could technically receive the item.
3. Intrinsic or specialized relevance — Interest, RecipientType, Profession, and GiftType describe characteristics the product actually represents. Be conservative. Generic product affinity belongs in Interest, not Relationship.

Category rules:
- Category is what the product is. Return exactly one intended primary merchandising Category ID.
- Do not return parent Category merely because the true leaf has a parent. Application code derives ancestors.
- Do not return extra secondary merchandising categories. Leave category_ids empty or containing only the primary ID.
- Choose the most specific valid merchandising category available. Use broad roots such as Fashion & Accessories only when no better fit exists in the catalog.
- Never attach a category merely to increase discovery coverage or because you are uncertain between families. Choose the best primary product family.
- Never use a recipient-, relationship-, occasion-, or SEO-intent-shaped category as primary.
- Never use retired/composite Categories or Personalized Gifts as a category. Personalization is a GiftType. A personalized wallet belongs in Fashion & Accessories or Jewellery, with GiftType Personalized Gifts.
- Primary Category confidence measures confidence that the selected Category is the best correct Category among the taxonomy values currently available.
- Do not lower primary Category confidence merely because a more specific taxonomy leaf could theoretically exist. A scrapbook that honestly belongs in Stationery & Office may have high primary Category confidence while separately reporting a Photo Albums & Scrapbooks taxonomy gap. Those two signals are independent.

Trusted Relationship hints:
- Wishlist hints are curator-provided strong evidence, not guaranteed truth.
- Trusted source Relationship hints should normally be retained unless the product clearly contradicts them.
- You may add other Relationships only when each added recipient has independent, high-confidence affinity. Do not expand a Boyfriend/Husband hint into every other male Relationship merely because the product is masculine or generic.
- Unhinted products have no such evidence and must be classified more conservatively.
- Do not treat quarterly archive or unclassified inbox membership as Relationship evidence.
- Evidence strength, strongest first: trusted wishlist Relationship hint > product-title or presentation recipient evidence > generic demographic suitability > "anyone could receive this". Title wording must not override trusted provenance. Do not drop a valid trusted hint merely because the title is generic.

Relationship rules:
- Relationship classification must be selective. Precision is more important than recall.
- Assign a Relationship only when the product is a particularly plausible, natural, or useful gift for that recipient compared with the general population.
- Do not assign a Relationship merely because the product could technically be gifted to that person.
- Distinguish generic products from recipient-signalled products. Generic products contain no meaningful recipient signal in the title, design, wording, use case, or presentation (tyre inflator, vacuum cleaner, generic keyboard, generic Bluetooth speaker, gaming console, study lamp). These may correctly receive relationship_ids = []. For generic or unisex products, prefer few or no Relationship assignments unless there is clear recipient affinity. Do not add weak tags merely to avoid zero.
- Recipient-signalled products provide meaningful evidence about likely gift recipients (jewelry box for women/girls, makeup organizer, couple mug set, mother-themed pendant, husband anniversary keepsake, boyfriend wallet card, bride gift, father-themed mug). These should receive a small, selective set of Relationships supported by that evidence. Do not leave them empty merely because they are unhinted.
- Gender suitability is supporting evidence for recipient affinity, not an instruction to enumerate every Relationship of that gender. Never invent Men, Women, or Unisex as Relationships, and do not expand "men's product" into Husband + Boyfriend + Father + Brother + Son automatically. Do not translate "for women" into every female Relationship. A jewelry box for women/girls may reasonably support a small subset such as Wife, Girlfriend, Mother, Sister, Daughter depending on the actual product. A men's grooming gift set may reasonably support Husband, Boyfriend, Father, Brother when the gifting context supports it.
- Explicit recipient wording such as "for husband", "for wife", "for boyfriend", "for girlfriend", "for mother", "for father", "for sister", "for brother", "for daughter", or "for son" is strong Relationship evidence. Normally retain the explicitly named active Relationship unless the title is clearly marketplace keyword spam or contradicts the actual product. Do not blindly trust every recipient keyword in a spammy marketplace title; use product identity and overall title context.
- Couple-oriented gifts are not Newlyweds-only. A couple mug set, romantic couple frame, couple activity book, or anniversary keepsake may naturally support Husband, Wife, Boyfriend, and Girlfriend where appropriate. Add Newlyweds only when wedding or newlywed positioning is actually supported. "Couple" does not mean Newlyweds only.
- Do not use Relationship as a substitute for Interest. A gaming keyboard may have Interest Gaming / Tech & Gadgets with few or no Relationships.
- Soft range for products with no trusted recipient hints: 0–4 Relationships is normal. 5–6 may be justified for genuinely broad recipient affinity. More than 6 should be uncommon and only used when the product has strong evidence for each selected Relationship. This is guidance, not a quota: do not pad to reach 4, and do not add weak tags to avoid zero. Zero Relationships remains valid for generic products.
- Wishlist hints remain stronger than title inference. Keep trusted hints unless product semantics clearly contradict a hint. Do not treat a keyword-stuffed marketplace title as proof that every named recipient fits, and do not let title inference replace or override trusted provenance.
- Gender-specific products must remain gender-compatible. A men's grooming kit may reasonably fit Husband / Boyfriend / Father / Brother when those recipients are genuinely plausible; it must not gain female Relationships merely for coverage, and it must not dump every male Relationship by default.
- Relationship-specific products must remain narrow. "Best Dad Ever" maps to Father, not to every relationship that could technically receive a mug. Mother-themed jewelry: Mother is strong. A romantic keepsake may reasonably fit Husband / Wife / Boyfriend / Girlfriend.
- Guidance examples (not hardcoded rules): a tyre inflator, vacuum cleaner, generic keyboard, or PlayStation console must not automatically receive Husband, Boyfriend, Father, Brother, Friends, Colleagues, Boss, and similar; Interest should carry that generic affinity. A generic Bluetooth speaker must not enumerate all adult Relationships. A generic notebook or generic sunglasses usually receive no Relationship unless title or design provides real recipient affinity. A jewelry box for women/girls or a makeup brush holder should not be left empty; assign a small defensible female-recipient set, not every female Relationship. A couple mug set should not collapse to Newlyweds only unless wedding positioning exists.
- Relationship confidence measures confidence that the selected Relationships have meaningful recipient affinity, not that someone could technically receive the product. Omit weak Relationships rather than listing them at low confidence to increase coverage.
- Assess each relationship independently. Never add a relationship when the gift would feel unnatural, forced, or only technically possible.

Occasion rules:
- Be conservative.
- Use broader eligibility for general occasions such as Birthday, Housewarming, Christmas, New Year, or similar supplied catalog entries when the product is naturally useful or relevant.
- Require stronger semantic fit for specific or emotional occasions such as Anniversary, Wedding, Engagement, Raksha Bandhan, or Baby Shower.
- Do not reason that any product can technically be given for every occasion. Prefer honest omission over weak tags and avoid occasion saturation.
- Do not force an arbitrary 1–3 target when additional general occasions genuinely fit.

Interests: product affinity only. 0–3 only when there is a meaningful semantic match. Zero is valid; do not add weak interests for coverage. Generic affinity belongs here: a camera accessory may use Photography with possibly no Relationships; a fitness tracker may use Fitness / Tech & Gadgets with only selective Relationships.

Recipient types: conservative. 0–2 only when useful. Zero is valid. Never default Adult onto every physical product, and do not treat unisex as equivalent to Adult. Never return inactive Adult. Pet means the animal recipient; gifts for the owner use the Pet Parent interest.

Professions: only genuine profession affinity. Normally 0 unless the item is genuinely occupation-specific. General work or laptop use does not make a product profession-specific.

Gift types: sparse. Normally 0 for ordinary physical retail goods (`gift_type_ids = []`). Return Gifts, Digital / Instant Gifts, Gift Cards, Subscriptions, Hampers / Gift Sets, Experience Gifts, and Personalized Gifts apply only when the product semantics truly match.

A curation_group hint such as men or women means only that the item was found while an operator curated that collection. It is soft merchandising context, not a recipient restriction. Product semantics override it. Do not strip a genuinely fitting Relationship merely because of the curation group, and do not enumerate extra Relationships merely because the item is unisex or came from a gendered collection. A men's wallet is male-compatible because of the product itself, not because of its curation group.

Do not return BudgetRange. Do not return Category ancestor IDs.

Taxonomy gaps:
- taxonomy_gap is independent of primary Category confidence. A high-confidence parent Category can still report an advisory gap.
- A taxonomy gap should only be reported when the missing concept is likely to justify a reusable merchandising destination across multiple products.
- Do not report a taxonomy gap merely because the current Category is broader than the exact product subtype. Toys & Games does not need "Royal Enfield Diecast Motorcycles". Electronics does not need "Mechanical Gaming Keyboards". Those are SKU or subtype noise.
- Report a gap only when it represents a recurring, useful merchandising distinction that many catalog products may need. A potentially useful example is Home & Living → Home Decor & Keepsakes, because it may represent a substantial reusable product cluster. Prefer broader reusable concepts over SKU-level microcategories.
- Do not invent a taxonomy row. Do not propose a category that already exists.
- severity = advisory when a valid existing merchandising Category accurately represents the product, but a more specific reusable leaf could improve taxonomy later.
- severity = blocking when the product cannot be represented honestly by the current Category taxonomy: no acceptable merchandising Category, only clearly incorrect Categories, or product identity itself is too ambiguous.
- If taxonomy_gap.detected is false, set severity, suggested_concept, and explanation to null.

Confidence is a quality signal from 0.00 to 1.00 per dimension, not hidden chain-of-thought. Relationship confidence is confidence in meaningful recipient affinity for the selected Relationships. Do not keep low-confidence Relationship assignments merely to increase coverage. reasoning_summary must be short and auditable.

Do not output price, affiliate URLs, external product IDs, image URLs, merchant IDs, slugs, SEO metadata, or publication status.

Output structured JSON only. No markdown.
PROMPT;
    }

    /**
     * @param  list<array{id: int, name: string, slug: string, description: ?string}>  $relationshipHints
     */
    public function userPayload(
        CuratedMerchantProductInput $input,
        string $merchantName,
        ?string $priceAmount,
        ?string $priceCurrency,
        CommercialTaxonomyCatalog $catalog,
        array $relationshipHints = [],
        ?string $existingShortDescription = null,
        ?string $existingDescription = null,
    ): string {
        $payload = [
            'curated_product' => [
                'title' => $input->title,
                'source_url' => $input->sourceUrl,
                'merchant_name' => $merchantName,
                'existing_short_description' => $existingShortDescription,
                'existing_description' => $existingDescription,
                'curation_group' => $input->curationGroup,
                'curation_group_note' => 'Soft operator context only: this is where the item was found, not a recipient restriction. Classify from product semantics. Do not enumerate extra Relationships merely because the item is unisex or came from a gendered collection.',
            ],
            'trusted_relationship_hints' => [
                'note' => 'Trusted source Relationship hints should normally be retained unless the product clearly contradicts them. They are stronger than product-title recipient evidence. Unhinted products have no such curator evidence and should be classified more conservatively, but recipient-signalled titles may still receive a small selective Relationship set. Quarterly archive and unclassified inbox are not hints.',
                'relationships' => $relationshipHints,
            ],
            'extracted_price' => $priceAmount === null ? null : [
                'amount' => $priceAmount,
                'currency' => $priceCurrency,
                'note' => 'Context only. Do not echo, invent, or change the price. Do not assign BudgetRange.',
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

        $confidenceScore = [
            'type' => 'number',
            'minimum' => 0,
            'maximum' => 1,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Concise Gift Expert catalog title. Omit marketplace seller prefixes that add no product identity. No keyword stuffing.',
                ],
                'short_description' => [
                    'type' => ['string', 'null'],
                    'description' => 'Factual one-line description of what the item is.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => '2–4 newline-separated reasons, normally 3, explaining why it is a great gift. No numbering or leading bullets.',
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
                'confidence' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'primary_category' => $confidenceScore,
                        'relationships' => $confidenceScore,
                        'recipient_types' => $confidenceScore,
                        'occasions' => $confidenceScore,
                        'interests' => $confidenceScore,
                        'professions' => $confidenceScore,
                        'gift_types' => $confidenceScore,
                    ],
                    'required' => [
                        'primary_category',
                        'relationships',
                        'recipient_types',
                        'occasions',
                        'interests',
                        'professions',
                        'gift_types',
                    ],
                ],
                'reasoning_summary' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'primary_category' => ['type' => 'string'],
                        'relationships' => ['type' => 'string'],
                    ],
                    'required' => ['primary_category', 'relationships'],
                ],
                'taxonomy_gap' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'detected' => ['type' => 'boolean'],
                        'severity' => [
                            'type' => ['string', 'null'],
                            'enum' => ['advisory', 'blocking', null],
                            'description' => 'advisory when a valid Category exists; blocking when the product cannot be represented honestly. Null when detected is false.',
                        ],
                        'suggested_concept' => ['type' => ['string', 'null']],
                        'explanation' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['detected', 'severity', 'suggested_concept', 'explanation'],
                ],
            ],
            'required' => [
                'name',
                'short_description',
                'description',
                'brand',
                'taxonomy',
                'confidence',
                'reasoning_summary',
                'taxonomy_gap',
            ],
        ];
    }
}
