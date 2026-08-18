<?php

namespace Tests\Unit\CatalogCandidate\Ingestion;

use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionParserException;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use App\CatalogCandidate\Ingestion\JsonCatalogCandidateParser;
use App\Enums\CatalogCandidateSourceType;
use Tests\TestCase;

class JsonCatalogCandidateParserTest extends TestCase
{
    public function test_it_parses_valid_json_with_evidence(): void
    {
        $rows = $this->parse(file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.json')));

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertSame(1, $rows[0]->index);
        $this->assertSame('Portable Photo Printer', $rows[0]->title);
        $this->assertSame(CatalogCandidateSourceType::Web, $rows[0]->sourceType);
        $this->assertCount(1, $rows[0]->evidence);
        $this->assertSame(CatalogCandidateSourceType::Community, $rows[0]->evidence[0]->sourceType);
        $this->assertSame('https://example.com/thread', $rows[0]->evidence[0]->sourceUrl);
        $this->assertSame(['thread_id' => 't-1'], $rows[0]->evidence[0]->metadata);
    }

    public function test_malformed_json_is_fatal(): void
    {
        $this->expectException(CatalogCandidateIngestionParserException::class);
        $this->expectExceptionMessage('malformed');

        $this->parse('{');
    }

    public function test_extra_top_level_keys_are_fatal(): void
    {
        $this->expectException(CatalogCandidateIngestionParserException::class);
        $this->expectExceptionMessage('Unknown top-level JSON fields');

        $this->parse(json_encode([
            'candidates' => [],
            'meta' => ['batch' => 1],
        ]));
    }

    public function test_unknown_candidate_fields_fail_the_item(): void
    {
        $rows = $this->parse(json_encode([
            'candidates' => [[
                'title' => 'Desk Lamp',
                'source_type' => 'manual',
                'status' => 'discovered',
            ]],
        ]));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertFalse($rows[0]->skip);
        $this->assertStringContainsString('status', $rows[0]->message);
    }

    public function test_a_non_object_candidate_fails_the_item(): void
    {
        $rows = $this->parse(json_encode([
            'candidates' => ['Desk Lamp'],
        ]));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame('Each candidate must be an object.', $rows[0]->message);
    }

    /**
     * @return list<IngestedCatalogCandidate|IngestionRowError>
     */
    private function parse(string $contents): array
    {
        return iterator_to_array(app(JsonCatalogCandidateParser::class)->parse($contents), false);
    }
}
