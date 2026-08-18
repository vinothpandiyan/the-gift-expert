<?php

namespace Tests\Feature\CatalogCandidate;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogCandidateFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_candidate_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('catalog_candidates'));
        $this->assertTrue(Schema::hasTable('catalog_candidate_evidence'));

        foreach ([
            'id',
            'title',
            'title_fingerprint',
            'summary',
            'notes',
            'status',
            'priority',
            'source_type',
            'source_name',
            'source_url',
            'external_reference',
            'estimated_price_amount',
            'estimated_price_currency',
            'discovered_at',
            'last_evaluated_at',
            'reviewed_at',
            'created_by_user_id',
            'reviewed_by_user_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('catalog_candidates', $column), "Missing column: {$column}");
        }

        foreach ([
            'id',
            'catalog_candidate_id',
            'source_type',
            'source_name',
            'source_url',
            'summary',
            'observed_at',
            'metadata',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('catalog_candidate_evidence', $column), "Missing column: {$column}");
        }
    }

    public function test_user_foreign_keys_are_nullable_and_null_when_the_user_is_deleted(): void
    {
        $creator = User::factory()->create();
        $reviewer = User::factory()->create();

        $candidate = CatalogCandidate::factory()->create([
            'created_by_user_id' => $creator->id,
            'reviewed_by_user_id' => $reviewer->id,
        ]);

        $this->assertTrue($candidate->createdBy->is($creator));
        $this->assertTrue($candidate->reviewedBy->is($reviewer));

        $creator->delete();
        $reviewer->delete();

        $candidate->refresh();

        $this->assertNull($candidate->created_by_user_id);
        $this->assertNull($candidate->reviewed_by_user_id);
        $this->assertNull($candidate->createdBy);
        $this->assertNull($candidate->reviewedBy);
    }

    public function test_force_deleting_a_candidate_cascades_evidence(): void
    {
        $candidate = CatalogCandidate::factory()->create();
        $evidence = CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $candidate->id,
        ]);

        $candidate->forceDelete();

        $this->assertDatabaseMissing('catalog_candidates', ['id' => $candidate->id]);
        $this->assertDatabaseMissing('catalog_candidate_evidence', ['id' => $evidence->id]);
    }

    public function test_soft_deleting_a_candidate_preserves_the_row_and_evidence(): void
    {
        $candidate = CatalogCandidate::factory()->create();
        $evidence = CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $candidate->id,
        ]);

        $candidate->delete();

        $this->assertSoftDeleted('catalog_candidates', ['id' => $candidate->id]);
        $this->assertDatabaseHas('catalog_candidate_evidence', ['id' => $evidence->id]);
        $this->assertTrue($evidence->fresh()->candidate()->withTrashed()->first()->is($candidate));
    }

    public function test_candidate_model_casts_and_evidence_relationship(): void
    {
        $discoveredAt = now()->startOfSecond();

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
            'status' => CatalogCandidateStatus::Discovered,
            'priority' => CatalogCandidatePriority::Normal,
            'source_type' => CatalogCandidateSourceType::Manual,
            'estimated_price_amount' => '1299.50',
            'estimated_price_currency' => 'INR',
            'discovered_at' => $discoveredAt,
        ]);

        $evidence = $candidate->evidence()->create([
            'source_type' => CatalogCandidateSourceType::Web,
            'source_name' => 'Example Forum',
            'source_url' => 'https://example.com/thread',
            'summary' => 'Mentioned as a popular gift.',
            'observed_at' => $discoveredAt,
            'metadata' => ['thread_id' => 'abc-123'],
        ]);

        $candidate->refresh();
        $evidence->refresh();

        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
        $this->assertSame(CatalogCandidatePriority::Normal, $candidate->priority);
        $this->assertSame(CatalogCandidateSourceType::Manual, $candidate->source_type);
        $this->assertSame('1299.50', $candidate->estimated_price_amount);
        $this->assertTrue($discoveredAt->equalTo($candidate->discovered_at));
        $this->assertSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            $candidate->title_fingerprint,
        );
        $this->assertTrue($candidate->evidence->contains($evidence));
        $this->assertTrue($evidence->candidate->is($candidate));
        $this->assertSame(CatalogCandidateSourceType::Web, $evidence->source_type);
        $this->assertTrue($discoveredAt->equalTo($evidence->observed_at));
        $this->assertSame(['thread_id' => 'abc-123'], $evidence->metadata);
    }

    public function test_multiple_candidates_may_share_the_same_source_url(): void
    {
        $url = 'https://example.com/gift-roundup';

        $first = CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
            'source_url' => $url,
        ]);
        $second = CatalogCandidate::factory()->create([
            'title' => 'Leather Wallet',
            'source_url' => $url,
        ]);

        $this->assertSame($url, $first->source_url);
        $this->assertSame($url, $second->source_url);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_the_same_evidence_url_may_support_two_candidates(): void
    {
        $url = 'https://example.com/gift-roundup';
        $first = CatalogCandidate::factory()->create();
        $second = CatalogCandidate::factory()->create();

        $firstEvidence = CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $first->id,
            'source_url' => $url,
        ]);
        $secondEvidence = CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $second->id,
            'source_url' => $url,
        ]);

        $this->assertSame($url, $firstEvidence->source_url);
        $this->assertSame($url, $secondEvidence->source_url);
    }

    public function test_the_same_candidate_cannot_receive_duplicate_evidence_source_urls(): void
    {
        $candidate = CatalogCandidate::factory()->create();
        $url = 'https://example.com/thread';

        CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $candidate->id,
            'source_url' => $url,
        ]);

        $this->expectException(QueryException::class);

        CatalogCandidateEvidence::factory()->create([
            'catalog_candidate_id' => $candidate->id,
            'source_url' => $url,
        ]);
    }

    public function test_creating_a_candidate_does_not_touch_the_product_catalog(): void
    {
        $before = $this->catalogCounts();

        CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/idea',
        ]);

        $this->assertSame($before, $this->catalogCounts());
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
