<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\GroundDiscoveredCandidatesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryResult;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use App\Support\CatalogCandidateTitleFingerprint;
use Tests\TestCase;

class GroundDiscoveredCandidatesActionTest extends TestCase
{
    public function test_grounded_evidence_is_normalized_through_existing_ingestion_fields(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($this->discoveryResult([
            $this->candidate('Portable Photo Printer', [
                $this->evidence('https://example.com/roundup'),
            ]),
        ]));

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertSame('Portable Photo Printer', $rows[0]->title);
        $this->assertCount(1, $rows[0]->evidence);
        $this->assertSame('https://example.com/roundup', $rows[0]->evidence[0]->sourceUrl);
    }

    public function test_missing_evidence_is_rejected(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($this->discoveryResult([
            [
                'title' => 'Portable Photo Printer',
                'source_type' => 'ai_research',
            ],
        ]));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertFalse($rows[0]->skip);
        $this->assertSame(
            'Discovered candidates must include at least one evidence URL from the retrieved sources.',
            $rows[0]->message,
        );
    }

    public function test_evidence_urls_absent_from_the_corpus_are_rejected(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($this->discoveryResult([
            $this->candidate('Portable Photo Printer', [
                $this->evidence('https://invented.example.com/made-up'),
            ]),
        ]));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame('Evidence URLs must match a retrieved source URL.', $rows[0]->message);
    }

    public function test_www_host_variants_are_still_rejected_without_synthesis_remap(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute(new CatalogCandidateDiscoveryResult(
            candidates: [
                $this->candidate('Brass diya or kumkum holder', [
                    $this->evidence('https://amazon.in/example?x=1'),
                ]),
            ],
            corpus: [
                new RetrievedCatalogCandidateSource(
                    url: 'https://www.amazon.in/example?x=1',
                    title: 'Return gifts',
                    snippet: 'Brass diya',
                    sourceName: 'amazon.in',
                    retrievedAt: now(),
                ),
            ],
        ));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame('Evidence URLs must match a retrieved source URL.', $rows[0]->message);
    }

    public function test_duplicate_proposed_title_fingerprints_are_collapsed(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($this->discoveryResult([
            $this->candidate('Portable Photo Printer', [
                $this->evidence('https://example.com/roundup'),
            ]),
            $this->candidate('portable photo-printer', [
                $this->evidence('https://example.com/thread'),
            ]),
        ]));

        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertInstanceOf(IngestionRowError::class, $rows[1]);
        $this->assertTrue($rows[1]->skip);
        $this->assertSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            CatalogCandidateTitleFingerprint::from((string) $rows[1]->title),
        );
    }

    public function test_malformed_candidates_become_ingestion_row_errors_through_existing_normalization(): void
    {
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($this->discoveryResult([
            $this->candidate('Portable Photo Printer', [
                $this->evidence('https://example.com/roundup'),
            ], extra: ['unknown_field' => 'nope']),
        ]));

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertStringContainsString('Unknown fields are not allowed', $rows[0]->message);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function discoveryResult(array $candidates): CatalogCandidateDiscoveryResult
    {
        return new CatalogCandidateDiscoveryResult(
            candidates: $candidates,
            corpus: [
                new RetrievedCatalogCandidateSource(
                    url: 'https://example.com/roundup',
                    title: 'Gift roundup',
                    snippet: 'Photo printers are popular.',
                    sourceName: 'example.com',
                    retrievedAt: '2026-08-18T00:00:00+00:00',
                ),
                new RetrievedCatalogCandidateSource(
                    url: 'https://example.com/thread',
                    title: 'Gift thread',
                    snippet: 'Massage guns were mentioned.',
                    sourceName: 'example.com',
                    retrievedAt: '2026-08-18T00:00:00+00:00',
                ),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function candidate(string $title, array $evidence, array $extra = []): array
    {
        return array_merge([
            'title' => $title,
            'source_type' => 'ai_research',
            'source_name' => 'Gift Candidate Research',
            'evidence' => $evidence,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(string $url): array
    {
        return [
            'source_type' => 'web',
            'source_name' => 'example.com',
            'source_url' => $url,
            'summary' => 'Mentioned as a gift idea.',
        ];
    }
}
