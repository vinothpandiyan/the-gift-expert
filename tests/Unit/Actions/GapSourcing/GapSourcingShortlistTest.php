<?php

namespace Tests\Unit\Actions\GapSourcing;

use App\Actions\GapSourcing\BuildGapSourcingShortlistAction;
use App\Actions\GapSourcing\ResolveGenuineCatalogGapsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\GapSourcingKind;
use App\Enums\GapSourcingOverlap;
use App\Enums\GapSourcingTriage;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class GapSourcingShortlistTest extends TestCase
{
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_father_without_keep_drafts_is_an_inventory_gap(): void
    {
        $father = Relationship::query()->create([
            'name' => 'Father',
            'slug' => 'father',
            'is_active' => true,
        ]);
        $unowned = Product::factory()->draft()->create(['slug' => 'unowned-father-draft']);
        $unowned->relationships()->sync([$father->id]);

        $gap = collect(app(ResolveGenuineCatalogGapsAction::class)->execute())
            ->firstWhere('key', 'father');

        $this->assertNotNull($gap);
        $this->assertSame(GapSourcingKind::Inventory, $gap->kind);
        $this->assertSame(0, $gap->publishedCount);
        $this->assertSame(0, $gap->keepFamilyDraftCount);
        $this->assertSame(1, $gap->otherDraftCount);
    }

    public function test_keep_family_drafts_make_a_gap_publication_not_a_sourcing_priority(): void
    {
        $father = Relationship::query()->create([
            'name' => 'Father',
            'slug' => 'father-keep',
            'is_active' => true,
        ]);
        $keep = $this->retainedDraft();
        $keep['product']->relationships()->sync([$father->id]);

        $gap = collect(app(ResolveGenuineCatalogGapsAction::class)->execute())
            ->firstWhere('key', 'father');

        $this->assertSame(GapSourcingKind::Publication, $gap?->kind);
        $this->assertSame(1, $gap?->keepFamilyDraftCount);

        $report = app(BuildGapSourcingShortlistAction::class)->execute([
            $this->candidate([
                'id' => 'another-dad-frame',
                'title' => 'Another Dad Frame',
                'asin' => 'B0NEWFRAME1',
                'source_url' => 'https://www.amazon.in/dp/B0NEWFRAME1',
                'father_specific_reason' => 'A photo a child would give their father.',
                'differentiated' => true,
            ]),
        ]);

        $this->assertSame(
            'publication_backlog_not_a_sourcing_priority',
            $report->items[0]->rejectReason,
        );
        $this->assertSame(GapSourcingTriage::Reject, $report->items[0]->triage);
        $this->assertSame(ProductStatus::Draft, $keep['product']->fresh()->status);
    }

    public function test_gap_scoped_discovery_dedupes_compares_concepts_and_does_not_mutate_catalog(): void
    {
        $this->seedPrimaryTaxonomy();

        $existing = $this->retainedDraft(productOverrides: [
            'name' => 'Published Wallet',
            'slug' => 'published-wallet',
        ]);
        $existing['product']->update([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
        $existing['audit']->update(['concept_key' => 'wallet']);
        AffiliateLink::query()->where('product_id', $existing['product']->id)->update([
            'external_product_id' => 'B0EXISTING1',
            'status' => AffiliateLinkStatus::Active,
        ]);

        $before = $this->mutationSnapshot();

        $report = app(BuildGapSourcingShortlistAction::class)->execute([
            $this->candidate([
                'id' => 'dad-star-map',
                'title' => 'Personalised Star Map for Dad',
                'concept' => 'personalised-star-map',
                'asin' => 'B08KMZ7HP8',
                'source_url' => 'https://www.amazon.in/dp/B08KMZ7HP8',
                'price_amount' => 3000,
                'gift_intents' => ['sentimental', 'premium'],
                'father_specific_reason' => 'This captures the night sky from a child\'s birth for a father to keep.',
                'differentiated' => true,
                'features' => ['Date, time, and place of a family event'],
                'evidence_confidence' => 'high',
            ]),
            $this->candidate([
                'id' => 'dad-star-map-duplicate',
                'title' => 'Personalised Star Map Duplicate Listing',
                'concept' => 'personalised-star-map',
                'asin' => 'B08KMZ7HP8',
                'source_url' => 'https://www.amazon.in/dp/B08KMZ7HP8',
                'price_amount' => 3000,
                'father_specific_reason' => 'This captures the night sky from a child\'s birth for a father to keep.',
                'differentiated' => true,
            ]),
            $this->candidate([
                'id' => 'generic-wallet',
                'title' => 'Generic RFID Wallet',
                'concept' => 'wallet',
                'asin' => 'B0NEWWALLET',
                'source_url' => 'https://www.amazon.in/dp/B0NEWWALLET',
                'price_amount' => 499,
                'gift_potential' => 'low',
                'father_specific_reason' => 'A man could use this.',
            ]),
            $this->candidate([
                'id' => 'generic-male-speaker',
                'title' => 'Generic Bluetooth Speaker',
                'concept' => 'portable-bluetooth-speaker',
                'asin' => 'B0NEWSPEAK1',
                'source_url' => 'https://www.amazon.in/dp/B0NEWSPEAK1',
                'price_amount' => 799,
                'gift_potential' => 'medium',
                'father_specific_reason' => 'A man could use this.',
            ]),
            $this->candidate([
                'id' => 'existing-asin',
                'title' => 'Relisted Existing Wallet',
                'concept' => 'wallet',
                'asin' => 'B0EXISTING1',
                'source_url' => 'https://www.amazon.in/dp/B0EXISTING1',
                'price_amount' => 499,
                'gift_potential' => 'medium',
                'father_specific_reason' => 'This wallet is engraved Best Dad.',
                'differentiated' => true,
            ]),
            $this->candidate([
                'id' => 'taj-card',
                'title' => 'Taj Experiences e-Gift Card',
                'merchant' => 'taj-hotels',
                'source_url' => 'https://www.tajhotels.com/en-in/gifting-and-shopping/e-gift-cards',
                'target_gap' => 'experience_gifts',
                'concept' => 'hotel-experience-gift-card',
                'is_gift_card' => true,
                'gift_potential' => 'medium',
                'experience_type' => 'gift card',
                'redemption_method' => 'Present the code at a hotel desk.',
            ]),
            $this->candidate([
                'id' => 'fnp-spa',
                'title' => 'FNP Sacred Serenity Spa Experience',
                'merchant' => 'fnp',
                'source_url' => 'https://www.fnp.com/gift/sacred-serenity-spa-experience',
                'target_gap' => 'experience_gifts',
                'concept' => 'hotel-spa-therapy-session',
                'gift_potential' => 'high',
                'gift_intents' => ['experience'],
                'experience_type' => 'spa/wellness',
                'location_restrictions' => 'Participating hotel spas',
                'validity' => 'Appointment required',
                'redemption_method' => 'Buy on fnp.com then book a 30-minute therapy.',
                'affiliate_viability' => 'FNP affiliate is disabled.',
                'recipient_suitability' => 'Adults',
                'differentiated' => true,
            ]),
        ]);

        $shortlisted = collect($report->shortlisted())
            ->keyBy(fn ($item) => $item->candidate->id);
        $rejected = collect($report->items)
            ->filter(fn ($item) => $item->triage === GapSourcingTriage::Reject)
            ->mapWithKeys(fn ($item) => [$item->candidate->id => $item->rejectReason]);

        $this->assertTrue($shortlisted->has('dad-star-map'));
        $this->assertSame(['Date, time, and place of a family event'], $shortlisted['dad-star-map']->candidate->features);
        $this->assertSame(GapSourcingOverlap::GenuineNewConcept, $shortlisted['dad-star-map']->nearest->overlap);
        $this->assertTrue($shortlisted['dad-star-map']->wishlistEligible);
        $this->assertSame('high', $shortlisted['dad-star-map']->candidate->evidenceConfidence);
        $this->assertTrue($shortlisted->has('fnp-spa'));
        $this->assertFalse($shortlisted['fnp-spa']->wishlistEligible);

        $this->assertSame('duplicate_candidate', $rejected['dad-star-map-duplicate']);
        $this->assertSame('generic_undifferentiated_concept', $rejected['generic-wallet']);
        $this->assertSame('not_father_specific', $rejected['generic-male-speaker']);
        $this->assertSame('exact_asin_already_in_catalog', $rejected['existing-asin']);
        $this->assertSame('gift_card_is_not_an_experience', $rejected['taj-card']);
        $this->assertSame(['dad-star-map-duplicate'], $report->duplicateIds);

        $this->assertSame($before, $this->mutationSnapshot());
        $this->assertSame($report->productCountBefore, $report->productCountAfter);
        $this->assertSame(0, $report->wishlistActions);
        $this->assertSame(0, $report->publishedMutations);
        $this->assertSame(0, $report->archivedMutations);
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Archived)->count());
        $this->assertSame(ProductStatus::Published, $existing['product']->fresh()->status);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, ProductCurationDecisionRecord::query()->where('decision', ProductCurationDecision::RemoveCandidate)->count());
    }

    public function test_secondary_gaps_are_not_expanded_by_default(): void
    {
        $keys = array_map(
            fn ($gap) => $gap->key,
            app(ResolveGenuineCatalogGapsAction::class)->execute(),
        );

        $this->assertSame(['father', 'experience_gifts', 'mothers_day', 'pet_parent', 'eco_conscious'], $keys);
    }

    private function seedPrimaryTaxonomy(): void
    {
        Relationship::query()->create(['name' => 'Father', 'slug' => 'father', 'is_active' => true]);
        GiftType::query()->create(['name' => 'Experience Gifts', 'slug' => 'experience-gifts', 'is_active' => true]);
        Occasion::query()->create(['name' => "Mother's Day", 'slug' => 'mothers-day', 'is_active' => true]);
        Interest::query()->create(['name' => 'Pet Parent', 'slug' => 'pets', 'is_active' => true]);
        Interest::query()->create(['name' => 'Eco-Conscious', 'slug' => 'eco-friendly', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides): array
    {
        return array_merge([
            'title' => 'Example Gift',
            'merchant' => 'amazon-in',
            'source_url' => 'https://www.amazon.in/dp/B0EXAMPLE1',
            'concept' => 'example-concept',
            'target_gap' => 'father',
            'gift_potential' => 'high',
            'availability' => 'in_stock',
        ], $overrides);
    }

    /**
     * @return array<string, int>
     */
    private function mutationSnapshot(): array
    {
        return [
            'products' => Product::query()->withTrashed()->count(),
            'published' => Product::query()->where('status', ProductStatus::Published)->count(),
            'archived' => Product::query()->where('status', ProductStatus::Archived)->count(),
            'candidates' => CatalogCandidate::query()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'decisions' => ProductCurationDecisionRecord::query()->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ];
    }
}
