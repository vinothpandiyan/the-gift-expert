<?php

namespace App\CuratedCatalog;

use App\Models\Product;

class CuratedProductSeoPrompt
{
    /**
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(Product $product): array
    {
        return [
            'system' => <<<'PROMPT'
You write search metadata for one draft product on The Gift Expert.

Use only the supplied cleaned Gift Expert editorial title, editorial copy, brand, and applied taxonomy. Never use or reconstruct an Amazon marketplace title. Do not add keyword lists, seller language, prices, availability, ratings, superlatives, or unsupported claims.

Write:
- meta_title: natural, specific, useful to gift shoppers, at most 60 characters. Preserve the product identity. Add a recipient, occasion, or gift-type qualifier only when clearly supported.
- meta_description: one natural sentence, 120–160 characters when practical and never over 160. Explain the product and why it suits the supported gift intent without keyword stuffing.

Output structured JSON only. No markdown. Do not output meta_keywords.
PROMPT,
            'user' => (string) json_encode([
                'editorial_title' => $product->name,
                'short_description' => $product->short_description,
                'gift_reasons' => $product->description,
                'brand' => $product->brand,
                'categories' => $product->categories->pluck('name')->values()->all(),
                'relationships' => $product->relationships->pluck('name')->values()->all(),
                'recipient_types' => $product->recipientTypes->pluck('name')->values()->all(),
                'occasions' => $product->occasions->pluck('name')->values()->all(),
                'interests' => $product->interests->pluck('name')->values()->all(),
                'professions' => $product->professions->pluck('name')->values()->all(),
                'gift_types' => $product->giftTypes->pluck('name')->values()->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'meta_title' => [
                        'type' => 'string',
                        'description' => 'Natural search title, no more than 60 characters.',
                    ],
                    'meta_description' => [
                        'type' => 'string',
                        'description' => 'Natural search description, no more than 160 characters.',
                    ],
                ],
                'required' => ['meta_title', 'meta_description'],
            ],
        ];
    }
}
