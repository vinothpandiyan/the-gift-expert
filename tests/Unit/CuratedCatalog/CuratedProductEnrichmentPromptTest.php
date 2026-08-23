<?php

namespace Tests\Unit\CuratedCatalog;

use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductEnrichmentPrompt;
use Tests\TestCase;

class CuratedProductEnrichmentPromptTest extends TestCase
{
    public function test_schema_omits_model_controlled_commercial_fields(): void
    {
        $encoded = json_encode(app(CuratedProductEnrichmentPrompt::class)->jsonSchema());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('affiliate', $encoded);
        $this->assertStringNotContainsString('external_product', $encoded);
        $this->assertStringNotContainsString('image_url', $encoded);
        $this->assertStringNotContainsString('gift_reason', $encoded);
    }

    public function test_system_prompt_encodes_curated_merchandising_and_copy_contract(): void
    {
        $system = app(CuratedProductEnrichmentPrompt::class)->systemInstructions();

        $this->assertStringContainsString('already curated this SKU as gift-worthy', $system);
        $this->assertStringContainsString('short_description', $system);
        $this->assertStringContainsString('why it works as a gift', $system);
        $this->assertStringContainsString('Product identity', $system);
        $this->assertStringContainsString('Gift eligibility', $system);
        $this->assertStringContainsString('Intrinsic or specialized relevance', $system);
        $this->assertStringContainsString('every active relationship', $system);
        $this->assertStringContainsString('Generic or unisex products', $system);
        $this->assertStringContainsString('Gender-specific products', $system);
        $this->assertStringContainsString('Relationship-specific products', $system);
        $this->assertStringContainsString('general occasions', $system);
        $this->assertStringContainsString('specific or emotional occasions', $system);
        $this->assertStringContainsString('avoid occasion saturation', $system);
        $this->assertStringNotContainsString('target 2–4', $system);
        $this->assertStringContainsString('Never default Adult', $system);
        $this->assertStringContainsString('unisex item from a men\'s wishlist', $system);
        $this->assertStringContainsString('Fashion & Accessories only when no better fit exists', $system);
        $this->assertStringContainsString('General work or laptop use does not make a product profession-specific', $system);
    }

    public function test_user_payload_includes_curation_group_note(): void
    {
        $input = new CuratedMerchantProductInput(
            itemIndex: 1,
            merchantSlug: 'amazon-in',
            externalProductId: 'B0ABCDEFGH',
            sourceUrl: 'https://www.amazon.in/dp/B0ABCDEFGH',
            title: 'SNEAKARE Quick Shoe Cleaning Kit',
            priceAmount: '499.00',
            priceCurrency: 'INR',
            sourceImageUrl: null,
            availability: 'in_stock',
            capturedAt: '2026-08-23T13:00:00+05:30',
            curationGroup: 'men',
            sourcePayload: [],
        );
        $catalog = new CommercialTaxonomyCatalog(
            categories: [['id' => 1, 'name' => 'Fashion & Accessories', 'slug' => 'fashion-and-accessories', 'parent_id' => null, 'full_path' => 'fashion-and-accessories']],
            occasions: [],
            relationships: [],
            recipientTypes: [],
            interests: [],
            professions: [],
            giftTypes: [],
        );

        $user = app(CuratedProductEnrichmentPrompt::class)->userPayload(
            $input,
            'Amazon India',
            '499.00',
            'INR',
            $catalog,
        );
        $decoded = json_decode($user, true);

        $this->assertSame('men', $decoded['curated_product']['curation_group']);
        $this->assertArrayHasKey('curation_group_note', $decoded['curated_product']);
        $this->assertStringContainsString('Soft operator context', $decoded['curated_product']['curation_group_note']);
        $this->assertStringContainsString('not a recipient restriction', $decoded['curated_product']['curation_group_note']);
    }
}
