<?php

namespace Tests\Support;

trait FakesCommercialEnrichment
{
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function commercialEnrichmentCompletion(array $fields): array
    {
        $taxonomy = array_merge([
            'primary_category_id' => null,
            'category_ids' => [],
            'occasion_ids' => [],
            'relationship_ids' => [],
            'recipient_type_ids' => [],
            'interest_ids' => [],
            'profession_ids' => [],
            'gift_type_ids' => [],
        ], is_array($fields['taxonomy'] ?? null) ? $fields['taxonomy'] : []);

        $confidence = array_merge([
            'primary_category' => 0.95,
            'relationships' => 0.90,
            'recipient_types' => 0.90,
            'occasions' => 0.90,
            'interests' => 0.90,
            'professions' => 0.95,
            'gift_types' => 0.95,
        ], is_array($fields['confidence'] ?? null) ? $fields['confidence'] : []);

        $payload = [
            'name' => $fields['name'] ?? 'BrandX French Press',
            'short_description' => $fields['short_description'] ?? 'A compact french press.',
            'description' => $fields['description'] ?? 'A stainless steel french press for home brewing.',
            'brand' => $fields['brand'] ?? 'BrandX',
            'taxonomy' => $taxonomy,
            'confidence' => $confidence,
            'reasoning_summary' => array_merge([
                'primary_category' => 'Product family matches the supplied merchandising category.',
                'relationships' => 'Eligible relationships follow product semantics.',
            ], is_array($fields['reasoning_summary'] ?? null) ? $fields['reasoning_summary'] : []),
            'taxonomy_gap' => array_merge([
                'detected' => false,
                'severity' => null,
                'suggested_concept' => null,
                'explanation' => null,
            ], is_array($fields['taxonomy_gap'] ?? null) ? $fields['taxonomy_gap'] : []),
        ];

        if (array_key_exists('extra', $fields) && is_array($fields['extra'])) {
            $payload = array_merge($payload, $fields['extra']);
        }

        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ];
    }
}
