<?php

namespace App\CatalogCandidate\Ingestion;

use JsonException;

class JsonCatalogCandidateParser implements CatalogCandidateIngestionParser
{
    public function parse(string $contents): iterable
    {
        $contents = CatalogCandidateIngestionFields::assertUtf8($contents);

        try {
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CatalogCandidateIngestionParserException('The JSON file is malformed.');
        }

        if (! is_object($decoded)) {
            throw new CatalogCandidateIngestionParserException('The JSON file must be an object with a candidates array.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $unknown = CatalogCandidateIngestionFields::unknownKeys($payload, ['candidates']);

        if ($unknown !== []) {
            throw new CatalogCandidateIngestionParserException('Unknown top-level JSON fields are not allowed: '.implode(', ', $unknown).'.');
        }

        if (! array_key_exists('candidates', $payload) || ! is_array($payload['candidates']) || ! array_is_list($payload['candidates'])) {
            throw new CatalogCandidateIngestionParserException('The JSON file must include a candidates array.');
        }

        $maxItems = (int) config('catalog_candidate_ingestion.max_items');

        if (count($payload['candidates']) > $maxItems) {
            throw new CatalogCandidateIngestionParserException('The ingestion file exceeds the maximum number of items.');
        }

        foreach ($payload['candidates'] as $offset => $item) {
            $index = $offset + 1;

            if (! is_array($item) || array_is_list($item)) {
                yield new IngestionRowError($index, 'Each candidate must be an object.');

                continue;
            }

            yield CatalogCandidateIngestionFields::candidateFromRow($index, $item, allowEvidence: true);
        }
    }
}
