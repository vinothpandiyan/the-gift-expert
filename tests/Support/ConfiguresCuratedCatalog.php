<?php

namespace Tests\Support;

use App\Models\Merchant;
use App\Models\Relationship;

trait ConfiguresCuratedCatalog
{
    protected function configureCuratedAmazonMerchant(): Merchant
    {
        config([
            'commercial_sourcing.enrichment.api_key' => 'sk-test-key',
            'commercial_sourcing.enrichment.model' => 'test-model',
            'commercial_sourcing.enrichment.base_url' => 'https://api.openai.com/v1',
            'commercial_sourcing.merchants.amazon-in' => [
                'enabled' => true,
                'priority' => 100,
                'markets' => ['IN'],
                'search_enabled' => true,
                'affiliate_enabled' => true,
                'domains' => ['amazon.in'],
                'image_policy_key' => 'amazon_associates',
                'external_id_strategy' => 'extractor',
                'external_id' => [
                    'rules' => [
                        ['type' => 'path_regex', 'pattern' => '#/dp/([A-Z0-9]{10})(?:[/?]|$)#i'],
                        ['type' => 'path_regex', 'pattern' => '#/gp/product/([A-Z0-9]{10})(?:[/?]|$)#i'],
                        ['type' => 'query', 'param' => 'asin'],
                    ],
                ],
                'url_fingerprint' => [
                    'enabled' => false,
                    'strip_query_params' => ['tag'],
                ],
                'affiliate_strategy' => 'query_param',
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'tag',
                    'value' => 'test-tag-20',
                    'allowed_domains' => ['amazon.in'],
                ],
                'deny_path_patterns' => [
                    '#^/s(/|$)#',
                    '#/gp/search#',
                ],
            ],
        ]);

        return Merchant::query()->updateOrCreate(
            ['slug' => 'amazon-in'],
            [
                'name' => 'Amazon India',
                'affiliate_network' => 'amazon_associates',
                'website_url' => 'https://www.amazon.in/',
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function curatedPayload(array $overrides = []): string
    {
        $payload = [
            'version' => 1,
            'merchant' => 'amazon-in',
            'captured_at' => '2026-08-23T13:00:00+05:30',
            'context' => [
                'curation_group' => 'men',
            ],
            'items' => [
                [
                    'external_product_id' => 'B0ABCDEFGH',
                    'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                    'title' => 'Stainless Steel French Press',
                    'price_amount' => '1299.00',
                    'price_currency' => 'INR',
                    'source_image_url' => 'https://m.media-amazon.com/images/I/example.jpg',
                    'availability' => 'in_stock',
                ],
            ],
        ];

        if (array_key_exists('items', $overrides)) {
            $payload['items'] = $overrides['items'];
            unset($overrides['items']);
        }

        $payload = array_replace_recursive($payload, $overrides);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $context
     */
    protected function curatedWishlistPayload(
        string $listName,
        array $items,
        array $context = [],
        array $overrides = [],
    ): string {
        $payload = [
            'version' => 2,
            'merchant' => 'amazon-in',
            'captured_at' => '2026-09-08T10:00:00+05:30',
            'context' => array_merge([
                'source_list_name' => $listName,
            ], $context),
            'items' => $items,
        ];

        $payload = array_replace_recursive($payload, $overrides);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function curatedWishlistItem(string $asin, array $overrides = []): array
    {
        return array_merge([
            'external_product_id' => $asin,
            'source_url' => 'https://www.amazon.in/dp/'.$asin,
            'title' => 'Gift '.$asin,
            'price_amount' => '1299.00',
            'price_currency' => 'INR',
            'source_image_url' => 'https://m.media-amazon.com/images/I/'.$asin.'._SS1200_.jpg',
            'availability' => 'in_stock',
        ], $overrides);
    }

    protected function seedCuratedRelationships(): void
    {
        foreach (['Husband', 'Boyfriend', 'Brother', 'Father'] as $sortOrder => $name) {
            Relationship::query()->updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
