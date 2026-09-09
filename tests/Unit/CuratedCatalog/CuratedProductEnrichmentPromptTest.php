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
        $this->assertStringContainsString('why it is a great gift', $system);
        $this->assertStringContainsString('Omit marketplace seller/brand prefixes', $system);
        $this->assertStringContainsString('exactly 3', $system);
        $this->assertStringContainsString('roughly 6–14 words', $system);
        $this->assertStringContainsString('Product identity', $system);
        $this->assertStringContainsString('Gift eligibility', $system);
        $this->assertStringContainsString('Intrinsic or specialized relevance', $system);
        $this->assertStringContainsString('particularly plausible gift targets', $system);
        $this->assertStringContainsString('Relationship classification must be selective', $system);
        $this->assertStringContainsString('Precision is more important than recall', $system);
        $this->assertStringContainsString('Gender-specific products', $system);
        $this->assertStringContainsString('Relationship-specific products', $system);
        $this->assertStringContainsString('general occasions', $system);
        $this->assertStringContainsString('specific or emotional occasions', $system);
        $this->assertStringContainsString('avoid occasion saturation', $system);
        $this->assertStringNotContainsString('target 2–4', $system);
        $this->assertStringContainsString('Never default Adult', $system);
        $this->assertStringContainsString('do not enumerate extra Relationships merely because the item is unisex', $system);
        $this->assertStringContainsString('Fashion & Accessories only when no better fit exists', $system);
        $this->assertStringContainsString('General work or laptop use does not make a product profession-specific', $system);
        $this->assertStringContainsString('exactly one intended primary merchandising Category', $system);
        $this->assertStringContainsString('Application code derives ancestors', $system);
        $this->assertStringContainsString('best correct Category among the taxonomy values currently available', $system);
        $this->assertStringContainsString('Do not lower primary Category confidence merely because a more specific taxonomy leaf', $system);
        $this->assertStringContainsString('recurring, useful merchandising distinction', $system);
        $this->assertStringContainsString('severity = advisory', $system);
        $this->assertStringContainsString('severity = blocking', $system);
        $this->assertStringContainsString('curator-provided strong evidence, not guaranteed truth', $system);
        $this->assertStringContainsString('Do not return BudgetRange', $system);
        $this->assertStringContainsString('Leave category_ids empty', $system);
    }

    public function test_system_prompt_emphasizes_unhinted_relationship_precision(): void
    {
        $system = app(CuratedProductEnrichmentPrompt::class)->systemInstructions();

        $this->assertStringContainsString('Do not assign a Relationship merely because the product could technically be gifted', $system);
        $this->assertStringContainsString('prefer few or no Relationship assignments', $system);
        $this->assertStringContainsString('0–4 Relationships is normal', $system);
        $this->assertStringContainsString('tyre inflator', $system);
        $this->assertStringContainsString('generic sunglasses', $system);
        $this->assertStringContainsString('Do not use Relationship as a substitute for Interest', $system);
        $this->assertStringContainsString('Trusted source Relationship hints should normally be retained', $system);
        $this->assertStringContainsString('Unhinted products have no such evidence', $system);
        $this->assertStringContainsString('Never invent Men, Women, or Unisex as Relationships', $system);
        $this->assertStringContainsString('do not expand "men\'s product"', $system);
        $this->assertStringContainsString('reusable merchandising destination across multiple products', $system);
        $this->assertStringContainsString('Do not report a taxonomy gap merely because the current Category is broader', $system);
        $this->assertStringContainsString('Royal Enfield Diecast Motorcycles', $system);
        $this->assertStringContainsString('Mechanical Gaming Keyboards', $system);
        $this->assertStringContainsString('Home Decor & Keepsakes', $system);
        $this->assertStringContainsString('meaningful recipient affinity', $system);
    }

    public function test_system_prompt_distinguishes_generic_and_recipient_signalled_products(): void
    {
        $system = app(CuratedProductEnrichmentPrompt::class)->systemInstructions();

        $this->assertStringContainsString('Distinguish generic products from recipient-signalled products', $system);
        $this->assertStringContainsString('These may correctly receive relationship_ids = []', $system);
        $this->assertStringContainsString('jewelry box for women/girls', $system);
        $this->assertStringContainsString('small, selective set of Relationships', $system);
        $this->assertStringContainsString('Gender suitability is supporting evidence for recipient affinity', $system);
        $this->assertStringContainsString('Do not translate "for women" into every female Relationship', $system);
        $this->assertStringContainsString('Couple-oriented gifts are not Newlyweds-only', $system);
        $this->assertStringContainsString('"Couple" does not mean Newlyweds only', $system);
        $this->assertStringContainsString('Explicit recipient wording', $system);
        $this->assertStringContainsString('for husband', $system);
        $this->assertStringContainsString('marketplace keyword spam', $system);
        $this->assertStringContainsString('trusted wishlist Relationship hint', $system);
        $this->assertStringContainsString('Title wording must not override trusted provenance', $system);
        $this->assertStringContainsString('Zero Relationships remains valid for generic products', $system);
        $this->assertStringContainsString('Wishlist hints remain stronger than title inference', $system);
        $this->assertStringContainsString('makeup brush holder should not be left empty', $system);
        $this->assertStringContainsString('Friends is a real recipient Relationship', $system);
        $this->assertStringContainsString('meaningful friend-gifting affinity compared with the general population', $system);
        $this->assertStringContainsString('generic personalized caricatures or standees', $system);
        $this->assertStringContainsString('Do not add Friends to every personalized product', $system);
        $this->assertStringContainsString('Husband- or Boyfriend-specific caricature', $system);
        $this->assertStringNotContainsString('hard cap', $system);
        $this->assertStringNotContainsString('truncate', strtolower($system));
    }

    public function test_schema_requires_confidence_and_taxonomy_gap_and_omits_budget(): void
    {
        $schema = app(CuratedProductEnrichmentPrompt::class)->jsonSchema();
        $encoded = json_encode($schema);

        $this->assertContains('confidence', $schema['required']);
        $this->assertContains('taxonomy_gap', $schema['required']);
        $this->assertContains('reasoning_summary', $schema['required']);
        $this->assertContains('severity', $schema['properties']['taxonomy_gap']['required']);
        $this->assertSame(['advisory', 'blocking', null], $schema['properties']['taxonomy_gap']['properties']['severity']['enum']);
        $this->assertStringNotContainsString('budget', strtolower((string) $encoded));
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
        $this->assertStringContainsString('Do not enumerate extra Relationships', $decoded['curated_product']['curation_group_note']);
        $this->assertArrayHasKey('trusted_relationship_hints', $decoded);
        $this->assertSame([], $decoded['trusted_relationship_hints']['relationships']);
    }

    public function test_user_payload_includes_trusted_relationship_hints(): void
    {
        $input = new CuratedMerchantProductInput(
            itemIndex: 1,
            merchantSlug: 'amazon-in',
            externalProductId: 'B0ABCDEFGH',
            sourceUrl: 'https://www.amazon.in/dp/B0ABCDEFGH',
            title: 'Leather Wallet',
            priceAmount: '499.00',
            priceCurrency: 'INR',
            sourceImageUrl: null,
            availability: 'in_stock',
            capturedAt: '2026-08-23T13:00:00+05:30',
            curationGroup: null,
            sourcePayload: [],
        );
        $catalog = new CommercialTaxonomyCatalog(
            categories: [],
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
            [
                ['id' => 1, 'name' => 'Husband', 'slug' => 'husband', 'description' => 'Spouse'],
                ['id' => 2, 'name' => 'Boyfriend', 'slug' => 'boyfriend', 'description' => null],
            ],
        );
        $decoded = json_decode($user, true);

        $this->assertCount(2, $decoded['trusted_relationship_hints']['relationships']);
        $this->assertSame('Husband', $decoded['trusted_relationship_hints']['relationships'][0]['name']);
        $this->assertStringContainsString('Trusted source Relationship hints should normally be retained', $decoded['trusted_relationship_hints']['note']);
        $this->assertStringContainsString('They are stronger than product-title recipient evidence', $decoded['trusted_relationship_hints']['note']);
        $this->assertStringContainsString('Unhinted products have no such curator evidence', $decoded['trusted_relationship_hints']['note']);
        $this->assertStringContainsString('recipient-signalled titles may still receive a small selective Relationship set', $decoded['trusted_relationship_hints']['note']);
    }
}
