<?php

namespace Tests\Feature\CommercialSourcing;

use App\Actions\CatalogCandidate\SearchCommercialOffersAction;
use App\Actions\CatalogCandidate\SourceCatalogCandidatesAction;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\CatalogCandidateSourcingRunStatus;
use App\Enums\CatalogCandidateStatus;
use App\Enums\CommercialExternalIdSource;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\TestCase;

class SourceCatalogCandidatesActionTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'priority' => 80,
                'markets' => ['IN'],
            ]),
            'partner-us' => $this->commercialMerchantConfig('partner-us', [
                'domains' => ['partner-us.example'],
                'markets' => ['US'],
                'priority' => 90,
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
        $this->createActiveMerchant('partner-us');
    }

    public function test_it_persists_a_selected_offer_without_product_writes(): void
    {
        $this->fakeTavilyHits([
            [
                'title' => 'BrandX French Press',
                'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'content' => 'BrandX French Press ₹1,299',
            ],
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $before = $this->catalogCounts();

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            market: 'IN',
            candidateId: $candidate->id,
        );

        $this->assertFalse($result->dryRun);
        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertNotNull($result->run);
        $this->assertSame(CatalogCandidateSourcingRunStatus::Completed, $result->run->status);

        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertNotNull($item);
        $this->assertSame(CatalogCandidateSourcingItemStatus::Succeeded, $item->status);
        $this->assertNull($item->product_id);
        $this->assertNull($item->affiliate_link_id);
        $this->assertSame('B0ABCDEFGH', $item->selected_offer['external_product_id']);
        $this->assertSame(CommercialExternalIdSource::Extracted->value, $item->selected_offer['external_id_source']);
        $this->assertSame('1299.00', $item->selected_offer['price_amount']);
        $this->assertSame(CatalogCandidateStatus::Approved, $candidate->fresh()->status);
        $this->assertSame($before, $this->catalogCounts());
    }

    public function test_dry_run_writes_zero_sourcing_rows(): void
    {
        $this->fakeTavilyHits([
            [
                'title' => 'BrandX French Press',
                'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'content' => '₹1,299',
            ],
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            market: 'IN',
            candidateId: $candidate->id,
            dryRun: true,
        );

        $this->assertTrue($result->dryRun);
        $this->assertNull($result->run);
        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_it_skips_rejected_and_under_review_candidates(): void
    {
        $this->fakeTavilyHits([]);

        $rejected = CatalogCandidate::factory()->create([
            'title' => 'Rejected idea',
            'status' => CatalogCandidateStatus::Rejected,
        ]);
        $review = CatalogCandidate::factory()->create([
            'title' => 'Review idea',
            'status' => CatalogCandidateStatus::UnderReview,
        ]);

        $rejectedResult = app(SourceCatalogCandidatesAction::class)->execute(candidateId: $rejected->id);
        $reviewResult = app(SourceCatalogCandidatesAction::class)->execute(candidateId: $review->id);

        $this->assertSame('rejected', $rejectedResult->outcomes[0]->exceptionCodes[0]);
        $this->assertSame('under_review', $reviewResult->outcomes[0]->exceptionCodes[0]);
        $this->assertSame(CatalogCandidateStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(CatalogCandidateStatus::UnderReview, $review->fresh()->status);
    }

    public function test_discovered_candidates_require_an_explicit_flag(): void
    {
        $this->fakeTavilyHits([
            [
                'title' => 'BrandX French Press',
                'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'content' => '₹1,299',
            ],
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Discovered,
        ]);

        $blocked = app(SourceCatalogCandidatesAction::class)->execute(candidateId: $candidate->id);
        $this->assertSame(CatalogCandidateSourcingItemStatus::Skipped, $blocked->outcomes[0]->status);

        $allowed = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            includeDiscovered: true,
        );
        $this->assertSame(CatalogCandidateSourcingItemStatus::Succeeded, $allowed->outcomes[0]->status);
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->fresh()->status);
    }

    public function test_inactive_and_disabled_merchants_are_discarded(): void
    {
        $inactive = $this->createActiveMerchant('partner-inactive');
        $inactive->is_active = false;
        $inactive->save();

        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'enabled' => false,
                'domains' => ['partner-a.example'],
            ]),
            'partner-inactive' => $this->commercialMerchantConfig('partner-inactive', [
                'domains' => ['partner-inactive.example'],
            ]),
        ]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'Disabled',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => '₹100',
                    ],
                    [
                        'title' => 'Inactive',
                        'url' => 'https://partner-inactive.example/dp/B0INACTIVE1',
                        'content' => '₹100',
                    ],
                ],
            ], 200),
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $offers = app(SearchCommercialOffersAction::class)->execute($candidate, 'IN');

        $this->assertSame([], $offers['offers']);
    }

    public function test_market_restriction_discards_other_markets(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'US listing',
                        'url' => 'https://partner-us.example/dp/B0USMARKET1',
                        'content' => '$20',
                    ],
                ],
            ], 200),
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $offers = app(SearchCommercialOffersAction::class)->execute($candidate, 'IN');

        $this->assertSame([], $offers['offers']);
    }

    /**
     * @param  list<array<string, string>>  $hits
     */
    private function fakeTavilyHits(array $hits): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => $hits,
            ], 200),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        return [
            'products' => Product::query()->withTrashed()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'product_images' => ProductImage::query()->count(),
            'import_runs' => ImportRun::query()->count(),
            'category_product' => DB::table('category_product')->count(),
            'occasion_product' => DB::table('occasion_product')->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'recipient_type_product' => DB::table('recipient_type_product')->count(),
            'interest_product' => DB::table('interest_product')->count(),
            'profession_product' => DB::table('profession_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ];
    }
}
