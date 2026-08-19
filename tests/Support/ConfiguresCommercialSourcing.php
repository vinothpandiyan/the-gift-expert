<?php

namespace Tests\Support;

use App\Models\Merchant;

trait ConfiguresCommercialSourcing
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function commercialMerchantConfig(string $slug, array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'priority' => 50,
            'markets' => ['IN'],
            'search_enabled' => true,
            'affiliate_enabled' => true,
            'domains' => [$slug.'.example'],
            'image_policy_key' => 'fake',
            'external_id_strategy' => 'extractor',
            'external_id' => [
                'rules' => [
                    ['type' => 'path_regex', 'pattern' => '#/dp/([A-Z0-9]{10})(?:[/?]|$)#i'],
                    ['type' => 'query', 'param' => 'pid'],
                ],
            ],
            'url_fingerprint' => [
                'enabled' => false,
                'strip_query_params' => ['tag', 'ref', 'utm_source'],
            ],
            'affiliate_strategy' => 'query_param',
            'affiliate' => [
                'strategy' => 'query_param',
            ],
            'deny_path_patterns' => [
                '#^/s(/|$)#',
                '#^/search#',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, array<string, mixed>>  $merchants
     */
    protected function useCommercialMerchants(array $merchants): void
    {
        config([
            'commercial_sourcing.search.provider' => 'tavily',
            'commercial_sourcing.search.providers.tavily.api_key' => 'tvly-test-key',
            'commercial_sourcing.search.max_queries_per_candidate' => 2,
            'commercial_sourcing.search.max_results_per_query' => 8,
            'commercial_sourcing.merchants' => $merchants,
        ]);
    }

    protected function createActiveMerchant(string $slug, string $network = 'fake'): Merchant
    {
        return Merchant::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'affiliate_network' => $network,
            'website_url' => 'https://'.$slug.'.example',
            'is_active' => true,
        ]);
    }

    protected function configureCommercialEnrichment(): void
    {
        config([
            'commercial_sourcing.enrichment.api_key' => 'sk-test-key',
            'commercial_sourcing.enrichment.model' => 'test-model',
            'commercial_sourcing.enrichment.base_url' => 'https://api.openai.com/v1',
        ]);
    }
}
