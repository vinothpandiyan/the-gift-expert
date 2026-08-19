<?php

namespace Tests\Unit\CatalogCandidate;

use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\CommercialSourcing\GroundedCommercialEnrichmentPrompt;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CommercialExternalIdSource;
use App\Models\CatalogCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroundedCommercialEnrichmentPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_omits_model_controlled_commercial_fields(): void
    {
        $encoded = json_encode(app(GroundedCommercialEnrichmentPrompt::class)->jsonSchema());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('affiliate', $encoded);
        $this->assertStringNotContainsString('external_product', $encoded);
        $this->assertStringNotContainsString('image_url', $encoded);
        $this->assertStringNotContainsString('price', $encoded);
        $this->assertStringNotContainsString('slug', $encoded);
        $this->assertStringNotContainsString('meta_title', $encoded);
        $this->assertStringNotContainsString('status', $encoded);
    }

    public function test_system_prompt_encodes_gift_type_and_profession_conservatism(): void
    {
        $system = app(GroundedCommercialEnrichmentPrompt::class)->systemInstructions();

        $this->assertStringContainsString('Gift types', $system);
        $this->assertStringContainsString('ordinary physical products', $system);
        $this->assertStringContainsString('Professions', $system);
        $this->assertStringContainsString('genuinely profession-specific', $system);
        $this->assertStringContainsString('Do not output price', $system);
    }

    public function test_user_payload_includes_candidate_offer_and_catalog_without_budget_ranges(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'summary' => 'A brew method for coffee at home.',
        ]);
        $offer = new SourcedMerchantOffer(
            merchantId: 1,
            merchantSlug: 'partner-a',
            sourceUrl: 'https://partner-a.example/dp/B0ABCDEFGH',
            normalizedUrl: 'https://partner-a.example/dp/B0ABCDEFGH',
            title: 'BrandX French Press',
            snippet: '₹1,299',
            externalProductId: 'B0ABCDEFGH',
            externalIdSource: CommercialExternalIdSource::Extracted,
            priceAmount: '1299.00',
            priceCurrency: 'INR',
            imageUrls: [],
            retrievedAt: now(),
            sourceEvidence: [],
            rankScore: 1,
        );
        $catalog = new CommercialTaxonomyCatalog(
            categories: [['id' => 1, 'name' => 'Home & Living', 'slug' => 'home-and-living', 'parent_id' => null, 'full_path' => 'home-and-living']],
            occasions: [],
            relationships: [],
            recipientTypes: [],
            interests: [],
            professions: [],
            giftTypes: [],
        );

        $user = app(GroundedCommercialEnrichmentPrompt::class)->userPayload(
            $candidate,
            $offer,
            'partner-a',
            '1299.00',
            'INR',
            $catalog,
            [['source_url' => 'https://example.com/press', 'summary' => 'Editorial mention.']],
        );
        $decoded = json_decode($user, true);

        $this->assertSame('French press', $decoded['candidate']['title']);
        $this->assertSame('BrandX French Press', $decoded['offer']['title']);
        $this->assertSame('1299.00', $decoded['extracted_price']['amount']);
        $this->assertArrayHasKey('categories', $decoded['taxonomy_catalog']);
        $this->assertArrayNotHasKey('budget_ranges', $decoded['taxonomy_catalog']);
    }
}
