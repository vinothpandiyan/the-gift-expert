<?php

namespace Tests\Unit\CatalogCandidate\Ingestion;

use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionParserException;
use App\CatalogCandidate\Ingestion\CsvCatalogCandidateParser;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use Tests\TestCase;

class CsvCatalogCandidateParserTest extends TestCase
{
    public function test_it_parses_valid_csv_rows(): void
    {
        $rows = $this->parse(file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.csv')));

        $this->assertCount(2, $rows);
        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertSame(2, $rows[0]->index);
        $this->assertSame('Portable Photo Printer', $rows[0]->title);
        $this->assertSame(CatalogCandidateSourceType::Web, $rows[0]->sourceType);
        $this->assertSame(CatalogCandidatePriority::High, $rows[0]->priority);
        $this->assertSame('49.99', $rows[0]->estimatedPriceAmount);
        $this->assertSame('USD', $rows[0]->estimatedPriceCurrency);
        $this->assertFalse($rows[0]->allowSimilarTitle);
        $this->assertSame([], $rows[0]->evidence);

        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[1]);
        $this->assertSame('Leather Wallet', $rows[1]->title);
        $this->assertSame(CatalogCandidateSourceType::Manual, $rows[1]->sourceType);
        $this->assertNull($rows[1]->priority);
    }

    public function test_it_strips_a_utf8_bom(): void
    {
        $csv = "\xEF\xBB\xBFtitle,source_type\nDesk Lamp,manual\n";

        $rows = $this->parse($csv);

        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertSame('Desk Lamp', $rows[0]->title);
    }

    public function test_it_skips_blank_rows(): void
    {
        $csv = "title,source_type\nDesk Lamp,manual\n,\nTravel Mug,web\n";

        $rows = $this->parse($csv);

        $this->assertCount(3, $rows);
        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertInstanceOf(IngestionRowError::class, $rows[1]);
        $this->assertTrue($rows[1]->skip);
        $this->assertSame('empty row', $rows[1]->message);
        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[2]);
    }

    public function test_it_fails_a_row_with_a_missing_title(): void
    {
        $csv = "title,source_type\n,manual\n";

        $rows = $this->parse($csv);

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertFalse($rows[0]->skip);
        $this->assertSame('A candidate title is required.', $rows[0]->message);
    }

    public function test_it_fails_a_row_with_an_invalid_enum(): void
    {
        $csv = "title,source_type,priority\nDesk Lamp,manual,urgent\n";

        $rows = $this->parse($csv);

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame('A valid candidate priority is required.', $rows[0]->message);
    }

    public function test_unknown_headers_are_fatal(): void
    {
        $this->expectException(CatalogCandidateIngestionParserException::class);
        $this->expectExceptionMessage('Unknown CSV columns');

        $this->parse("title,source_type,status\nDesk Lamp,manual,discovered\n");
    }

    /**
     * @return list<IngestedCatalogCandidate|IngestionRowError>
     */
    private function parse(string $contents): array
    {
        return iterator_to_array(app(CsvCatalogCandidateParser::class)->parse($contents), false);
    }
}
