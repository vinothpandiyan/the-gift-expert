<?php

namespace App\CatalogCuration;

use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationFitStrength;
use App\Enums\GiftIntent;

class ProductCurationSemanticPrompt
{
    /**
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        ProductCurationEvidence $evidence,
        CommercialTaxonomyCatalog $catalog,
    ): array {
        return [
            'system' => $this->systemInstructions(),
            'user' => (string) json_encode([
                'product_evidence' => $evidence->toArray(),
                'active_taxonomy' => $this->withoutIds($catalog->toPromptArray()),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(): string
    {
        return <<<'PROMPT'
Evaluate one product as an advisory gift curator. Use only supplied evidence. Missing facts stay unknown and must not be inferred. Ratings and reviews may be null.

SCORE ANCHORS
- recipient_desirability /20: 17-20 very likely appreciated with clear gift desirability; 13-16 good appeal but not universally exciting; 8-12 ordinary or context-dependent; 4-7 weak; 0-3 very poor.
- thoughtfulness_emotional_potential /15: 13-15 meaningfully communicates thought, emotion, connection, memory, care, or celebration; 9-12 moderately thoughtful; 5-8 mostly functional/common with limited emotional value; 1-4 transactional or impersonal; 0 none. A practical gift may still score moderately when it shows good understanding of the recipient.
- uniqueness /15: 13-15 distinctive idea or unusually differentiated execution; 9-12 interesting and less common; 5-8 common but legitimate gift concept; 1-4 extremely generic/commodity-like; 0 no meaningful differentiation. Common watches, wallets, perfumes, and speakers can remain desirable without high uniqueness.
- value_for_money /15: judge whether the current price feels reasonable for what the recipient receives and its gifting utility, not whether it is the cheapest in the market. 13-15 excellent perceived value; 10-12 good; 7-9 fair; 4-6 somewhat expensive; 1-3 poor; 0 known to offer effectively no value. Use current price, product type, features, known brand/quality indicators, and gifting utility when sufficient. Return null only when evidence is genuinely insufficient; unknown is not bad value.
- visual_gifting_appeal /10: 9-10 strong presentation or highly attractive; 7-8 clearly attractive/presentable; 4-6 acceptable but ordinary; 1-3 weak; 0 clearly unsuitable. Use only available evidence.
- practical_usefulness /10: 9-10 frequent use or meaningful need; 7-8 clearly useful; 4-6 some practical value; 1-3 limited; 0 none. Do not excessively penalize sentimental/decorative gifts whose primary value is not utility.
- social_media_shareability /5: 5 strong visual/demo/reaction hook; 4 good reel/short potential; 2-3 some usable angle; 1 limited; 0 none.

TAXONOMY FIT
Return no database IDs. Taxonomy references must use a supplied canonical name and slug. Evaluate every currently assigned Relationship, Occasion, Interest, and Gift Type exactly once; never omit an assignment. Ask whether The Gift Expert would confidently recommend this product when a shopper explicitly requests that exact context:
- strong: natural primary recommendation that could appear prominently;
- medium: relevant in particular circumstances, but not a default;
- weak: merely possible and should not drive discovery or ranking.
For each current assignment, separately set misleading_on_targeted_landing_page. Set it true only when surfacing the product on a landing page dedicated to that exact taxonomy would materially mislead shoppers; fit strength and this signal are distinct. A medium fit is not materially misleading merely because it is not strong. A weak broad Relationship can still be harmless for a generic useful gift, while recipient-specific wording can make it misleading. Occasion fit is stricter than broad Relationship fit. Interests require meaningful affinity. Gift Type describes the nature of the gift, not every possible use.

Suggestions may reference only active supplied taxonomy, must include a concise reason, and must be conservative. An additional suggestion is advisory, not evidence that current taxonomy is wrong. Do not attach broadly usable products to every relationship, infer recipients without evidence, treat ordinary merchandise as Valentine's/Anniversary appropriate merely because it can be given then, or infer interests from incidental properties.

CONCEPT AND DIFFERENTIATION
concept_key_candidate and concept_label_candidate describe the reusable underlying gift idea, not an exact SKU feature bundle. Exclude ASIN, brand, recipient, occasion, model, edition, colour, capacity, size, material, and marketing adjectives unless they fundamentally change the recipient experience. RGB, waterproof, premium, leather, and mini normally belong in differentiation, not concept identity. For example, JBL Go, boAt RGB, and mini waterproof portable speakers all use portable-bluetooth-speaker; RFID leather, premium bifold, and ordinary wallets normally use wallet. Keep genuinely different experiences separate, such as wallet, personalised-photo-wallet-card, and travel-document-organizer.

differentiation_strength describes why this SKU deserves to coexist with same-concept peers: strong is a compelling distinct role, medium is meaningful distinction, weak is minor distinction, none is undifferentiated. differentiation_signals must be concrete, such as trusted premium brand, strong value, personalisation, compact travel use, presentation, materials, price point, or special use case.

niche_contribution describes an underserved gifting scenario, not merely unusual features: strong clearly fills one, medium meaningfully contributes, weak is small/contextual, none has no meaningful niche. niche_signals must name the scenario and be empty when niche_contribution is none.

Scores are semantic evidence inputs, not final scores. Respect every declared range. Choose no more than three distinct gift intents. why_this_gift must name a meaningful recipient or gifting scenario and stay concise. Return concrete strengths. Do not choose a catalog role or recommend publication, taxonomy mutation, deletion, replacement, or removal.

Output strict structured JSON only. No markdown.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $strength = array_map(fn (CurationFitStrength $value): string => $value->value, CurationFitStrength::cases());
        $taxonomyEvaluation = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'strength' => ['type' => 'string', 'enum' => $strength],
                'reason' => ['type' => 'string'],
                'misleading_on_targeted_landing_page' => ['type' => 'boolean'],
            ],
            'required' => ['name', 'slug', 'strength', 'reason', 'misleading_on_targeted_landing_page'],
        ];
        $taxonomySuggestion = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'strength' => ['type' => 'string', 'enum' => $strength],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['name', 'slug', 'strength', 'reason'],
        ];
        $components = [];

        foreach ((array) config('catalog_curation.gift_score.semantic_components', []) as $key => $maximum) {
            $components[$key] = [
                'type' => $key === 'value_for_money' ? ['integer', 'null'] : 'integer',
                'minimum' => 0,
                'maximum' => (int) $maximum,
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'gift_components' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $components,
                    'required' => array_keys($components),
                ],
                'gift_intents' => [
                    'type' => 'array',
                    'maxItems' => (int) config('catalog_curation.limits.gift_intents', 3),
                    'items' => [
                        'type' => 'string',
                        'enum' => array_map(fn (GiftIntent $intent): string => $intent->value, GiftIntent::cases()),
                    ],
                ],
                'current_taxonomy_evaluations' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'relationships' => ['type' => 'array', 'items' => $taxonomyEvaluation],
                        'occasions' => ['type' => 'array', 'items' => $taxonomyEvaluation],
                        'interests' => ['type' => 'array', 'items' => $taxonomyEvaluation],
                        'gift_types' => ['type' => 'array', 'items' => $taxonomyEvaluation],
                    ],
                    'required' => ['relationships', 'occasions', 'interests', 'gift_types'],
                ],
                'taxonomy_suggestions' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'relationships' => ['type' => 'array', 'items' => $taxonomySuggestion],
                        'occasions' => ['type' => 'array', 'items' => $taxonomySuggestion],
                        'interests' => ['type' => 'array', 'items' => $taxonomySuggestion],
                        'gift_types' => ['type' => 'array', 'items' => $taxonomySuggestion],
                    ],
                    'required' => ['relationships', 'occasions', 'interests', 'gift_types'],
                ],
                'concept_key_candidate' => ['type' => 'string', 'maxLength' => (int) config('catalog_curation.limits.concept_key_candidate', 120)],
                'concept_label_candidate' => ['type' => 'string', 'maxLength' => (int) config('catalog_curation.limits.concept_label_candidate', 160)],
                'why_this_gift' => ['type' => 'string', 'maxLength' => (int) config('catalog_curation.limits.why_this_gift', 240)],
                'confidence' => [
                    'type' => 'string',
                    'enum' => array_map(fn (CurationAiConfidence $value): string => $value->value, CurationAiConfidence::cases()),
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => (int) config('catalog_curation.limits.strengths', 5),
                ],
                'differentiation_signals' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                'differentiation_strength' => ['type' => 'string', 'enum' => ['strong', 'medium', 'weak', 'none']],
                'niche_signals' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                'niche_contribution' => ['type' => 'string', 'enum' => ['strong', 'medium', 'weak', 'none']],
            ],
            'required' => [
                'gift_components',
                'gift_intents',
                'current_taxonomy_evaluations',
                'taxonomy_suggestions',
                'concept_key_candidate',
                'concept_label_candidate',
                'why_this_gift',
                'confidence',
                'strengths',
                'differentiation_signals',
                'differentiation_strength',
                'niche_signals',
                'niche_contribution',
            ],
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $taxonomy
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutIds(array $taxonomy): array
    {
        return array_map(
            fn (array $rows): array => array_map(
                fn (array $row): array => array_filter([
                    'name' => $row['name'] ?? null,
                    'slug' => $row['slug'] ?? null,
                    'description' => $row['description'] ?? null,
                    'full_path' => $row['full_path'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
                $rows,
            ),
            $taxonomy,
        );
    }
}
