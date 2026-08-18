<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryResult;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionFields;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use App\Support\CatalogCandidateTitleFingerprint;

class GroundDiscoveredCandidatesAction
{
    /**
     * @return list<IngestedCatalogCandidate|IngestionRowError>
     */
    public function execute(CatalogCandidateDiscoveryResult $result): array
    {
        $allowlist = $this->urlAllowlist($result->corpus);
        $seenFingerprints = [];
        $rows = [];

        foreach (array_values($result->candidates) as $offset => $candidate) {
            $index = $offset + 1;

            if (! is_array($candidate) || array_is_list($candidate)) {
                $rows[] = new IngestionRowError($index, 'Each candidate must be an object.');

                continue;
            }

            $payload = CatalogCandidateIngestionFields::compactPayload($candidate);
            $title = CatalogCandidateIngestionFields::nullableString($candidate['title'] ?? null);
            $groundingError = $this->groundingError($candidate, $allowlist);

            if ($groundingError !== null) {
                $rows[] = new IngestionRowError($index, $groundingError, $payload, title: $title);

                continue;
            }

            $row = CatalogCandidateIngestionFields::candidateFromRow($index, $candidate, allowEvidence: true);

            if ($row instanceof IngestionRowError) {
                $rows[] = $row;

                continue;
            }

            $fingerprint = CatalogCandidateTitleFingerprint::from($row->title);

            if (isset($seenFingerprints[$fingerprint])) {
                $rows[] = new IngestionRowError(
                    $index,
                    'A duplicate candidate title was already proposed.',
                    $row->sourcePayload,
                    skip: true,
                    title: $row->title,
                );

                continue;
            }

            $seenFingerprints[$fingerprint] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     * @return array<string, true>
     */
    private function urlAllowlist(array $corpus): array
    {
        $allowlist = [];

        foreach ($corpus as $source) {
            $url = trim($source->url);

            if ($url === '') {
                continue;
            }

            $allowlist[$url] = true;
        }

        return $allowlist;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, true>  $allowlist
     */
    private function groundingError(array $candidate, array $allowlist): ?string
    {
        $evidence = $candidate['evidence'] ?? null;

        if (! is_array($evidence) || ! array_is_list($evidence) || $evidence === []) {
            return 'Discovered candidates must include at least one evidence URL from the retrieved sources.';
        }

        $grounded = 0;

        foreach ($evidence as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return 'Each evidence item must be an object.';
            }

            $url = CatalogCandidateIngestionFields::nullableString($item['source_url'] ?? null);

            if ($url === null) {
                continue;
            }

            if (! isset($allowlist[$url])) {
                return 'Evidence URLs must match a retrieved source URL.';
            }

            $grounded++;
        }

        if ($grounded < 1) {
            return 'Discovered candidates must include at least one evidence URL from the retrieved sources.';
        }

        return null;
    }
}
