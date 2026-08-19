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

        $payload = [
            'name' => $fields['name'] ?? 'BrandX French Press',
            'short_description' => $fields['short_description'] ?? 'A compact french press.',
            'description' => $fields['description'] ?? 'A stainless steel french press for home brewing.',
            'brand' => $fields['brand'] ?? 'BrandX',
            'taxonomy' => $taxonomy,
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
