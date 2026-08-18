<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Actions\CatalogCandidate\IngestCatalogCandidatesAction;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\CatalogCandidate\Ingestion\CsvCatalogCandidateParser;
use App\CatalogCandidate\Ingestion\JsonCatalogCandidateParser;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionItemStatus;
use App\Enums\CatalogCandidateIngestionRunStatus;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestCatalogCandidatesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_creates_discovered_candidates_without_evidence(): void
    {
        $result = $this->ingestCsv(file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.csv')));

        $this->assertSame(2, $result->itemsSucceeded);
        $this->assertSame(0, $result->itemsFailed);
        $this->assertSame(CatalogCandidateIngestionRunStatus::Completed, $result->run->status);

        $candidates = CatalogCandidate::query()->orderBy('id')->get();

        $this->assertCount(2, $candidates);
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidates[0]->status);
        $this->assertSame('Portable Photo Printer', $candidates[0]->title);
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(2, $result->run->items()->count());
        $this->assertSame($candidates[0]->id, $result->run->items()->orderBy('id')->first()->catalog_candidate_id);
    }

    public function test_json_creates_evidence_through_the_existing_action(): void
    {
        $result = $this->ingestJson(file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.json')));

        $this->assertSame(1, $result->itemsSucceeded);
        $candidate = CatalogCandidate::query()->firstOrFail();

        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
        $this->assertCount(1, $candidate->evidence);
        $this->assertSame('https://example.com/thread', $candidate->evidence->first()->source_url);
        $this->assertSame(['thread_id' => 't-1'], $candidate->evidence->first()->metadata);
    }

    public function test_duplicate_candidates_are_skipped(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $result = $this->ingestCsv("title,source_type\nPortable Photo Printer,web\nLeather Wallet,manual\n");

        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertSame(1, $result->itemsSkipped);
        $this->assertSame(0, $result->itemsFailed);
        $this->assertSame(CatalogCandidateIngestionRunStatus::Completed, $result->run->status);
        $this->assertSame(2, CatalogCandidate::query()->count());
        $this->assertSame(CatalogCandidateIngestionItemStatus::Skipped, $result->outcomes[0]->status);
    }

    public function test_the_same_source_url_with_a_different_title_is_allowed(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/roundup',
        ]);

        $result = $this->ingestCsv("title,source_type,source_url\nLeather Wallet,web,https://example.com/roundup\n");

        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertSame(2, CatalogCandidate::query()->count());
    }

    public function test_allow_similar_title_is_honored(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $blocked = $this->ingestCsv("title,source_type\nPortable Photo-Printer,editorial\n");
        $this->assertSame(1, $blocked->itemsSkipped);
        $this->assertSame(1, CatalogCandidate::query()->count());

        $allowed = $this->ingestCsv("title,source_type,allow_similar_title,source_url\nPortable Photo-Printer,editorial,true,https://example.com/other\n");
        $this->assertSame(1, $allowed->itemsSucceeded);
        $this->assertSame(2, CatalogCandidate::query()->count());
    }

    public function test_a_malformed_item_does_not_stop_others(): void
    {
        $result = $this->ingestCsv("title,source_type\n,manual\nTravel Mug,web\n");

        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame(CatalogCandidateIngestionRunStatus::CompletedWithErrors, $result->run->status);
        $this->assertSame('Travel Mug', CatalogCandidate::query()->first()->title);
    }

    public function test_dry_run_reports_counts_without_writing(): void
    {
        $result = $this->ingestCsv(
            file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.csv')),
            dryRun: true,
        );

        $this->assertSame(2, $result->itemsSucceeded);
        $this->assertNull($result->run);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
    }

    public function test_dry_run_skips_duplicates_without_writing(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $result = $this->ingestCsv("title,source_type\nPortable Photo Printer,web\n", dryRun: true);

        $this->assertSame(1, $result->itemsSkipped);
        $this->assertSame(1, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_ingestion_does_not_create_products(): void
    {
        $this->ingestJson(file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.json')));

        $this->assertSame(0, Product::query()->count());
    }

    /**
     * @return CatalogCandidateIngestionResult
     */
    private function ingestCsv(string $contents, bool $dryRun = false)
    {
        return app(IngestCatalogCandidatesAction::class)->execute(
            app(CsvCatalogCandidateParser::class)->parse($contents),
            CatalogCandidateIngestionFormat::Csv,
            'candidates.csv',
            $dryRun,
        );
    }

    /**
     * @return CatalogCandidateIngestionResult
     */
    private function ingestJson(string $contents, bool $dryRun = false)
    {
        return app(IngestCatalogCandidatesAction::class)->execute(
            app(JsonCatalogCandidateParser::class)->parse($contents),
            CatalogCandidateIngestionFormat::Json,
            'candidates.json',
            $dryRun,
        );
    }
}
