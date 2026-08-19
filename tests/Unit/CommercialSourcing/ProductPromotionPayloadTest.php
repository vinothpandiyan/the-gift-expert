<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\ProductPromotionPayload;
use App\Import\ImportedCatalogItem;
use Tests\TestCase;

class ProductPromotionPayloadTest extends TestCase
{
    public function test_from_audit_array_hydrates_and_maps_to_imported_catalog_item(): void
    {
        $payload = ProductPromotionPayload::fromAuditArray([
            'catalog_candidate_id' => 12,
            'sourcing_item_id' => 34,
            'merchant_id' => 5,
            'external_product_id' => 'B0ABCDEFGH',
            'affiliate_url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test',
            'affiliate_ready' => true,
            'name' => 'BrandX French Press',
            'short_description' => 'Compact press.',
            'description' => 'A stainless steel french press.',
            'brand' => 'BrandX',
            'price_amount' => '1299.00',
            'price_currency' => 'INR',
            'budget_range_id' => 3,
            'primary_category_id' => 9,
            'taxonomy' => [
                'category_ids' => [9],
                'occasion_ids' => [2],
                'relationship_ids' => [],
                'recipient_type_ids' => [],
                'interest_ids' => [],
                'profession_ids' => [],
                'gift_type_ids' => [],
            ],
            'image_urls' => ['https://example.test/images/press.jpg'],
            'exception_codes' => [],
            'metadata' => ['enriched_at' => '2026-08-19T00:00:00+00:00'],
        ]);

        $item = $payload->toImportedCatalogItem();

        $this->assertInstanceOf(ImportedCatalogItem::class, $item);
        $this->assertSame('BrandX French Press', $item->name);
        $this->assertSame('1299.00', $item->price_amount);
        $this->assertSame('INR', $item->price_currency);
        $this->assertSame('https://partner-a.example/dp/B0ABCDEFGH?aff=test', $item->affiliate_url);
        $this->assertSame('B0ABCDEFGH', $item->external_product_id);
        $this->assertSame(['https://example.test/images/press.jpg'], $item->image_urls);
        $this->assertSame(9, $payload->toTaxonomyClassification()->primaryCategoryId);
    }
}
