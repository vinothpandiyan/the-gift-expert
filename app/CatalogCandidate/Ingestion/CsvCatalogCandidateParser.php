<?php

namespace App\CatalogCandidate\Ingestion;

class CsvCatalogCandidateParser implements CatalogCandidateIngestionParser
{
    public function parse(string $contents): iterable
    {
        $contents = CatalogCandidateIngestionFields::assertUtf8($contents);

        if (trim($contents) === '') {
            throw new CatalogCandidateIngestionParserException('The CSV file is empty.');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new CatalogCandidateIngestionParserException('The CSV file could not be read.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle);

        if ($header === false || $header === [null] || $header === []) {
            fclose($handle);

            throw new CatalogCandidateIngestionParserException('The CSV file must include a header row.');
        }

        $header = array_map(fn (mixed $column): string => is_string($column) ? trim($column) : '', $header);

        if (in_array('', $header, true) || count($header) !== count(array_unique($header))) {
            fclose($handle);

            throw new CatalogCandidateIngestionParserException('The CSV header row is invalid.');
        }

        $unknown = CatalogCandidateIngestionFields::unknownKeys(array_fill_keys($header, true), CatalogCandidateIngestionFields::CSV_KEYS);

        if ($unknown !== []) {
            fclose($handle);

            throw new CatalogCandidateIngestionParserException('Unknown CSV columns are not allowed: '.implode(', ', $unknown).'.');
        }

        if (! in_array('title', $header, true) || ! in_array('source_type', $header, true)) {
            fclose($handle);

            throw new CatalogCandidateIngestionParserException('CSV files require title and source_type columns.');
        }

        $maxItems = (int) config('catalog_candidate_ingestion.max_items');
        $items = 0;
        $rowNumber = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($cells === [null]) {
                $items++;

                if ($items > $maxItems) {
                    fclose($handle);

                    throw new CatalogCandidateIngestionParserException('The ingestion file exceeds the maximum number of items.');
                }

                yield new IngestionRowError($rowNumber, 'empty row', skip: true);

                continue;
            }

            $items++;

            if ($items > $maxItems) {
                fclose($handle);

                throw new CatalogCandidateIngestionParserException('The ingestion file exceeds the maximum number of items.');
            }

            if (count($cells) < count($header)) {
                $cells = array_pad($cells, count($header), '');
            }

            $row = [];

            foreach ($header as $offset => $column) {
                $row[$column] = $cells[$offset] ?? '';
            }

            if ($this->isEmptyRow($row)) {
                yield new IngestionRowError($rowNumber, 'empty row', $row, skip: true);

                continue;
            }

            yield CatalogCandidateIngestionFields::candidateFromRow($rowNumber, $row, allowEvidence: false);
        }

        fclose($handle);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (CatalogCandidateIngestionFields::nullableString(is_string($value) ? $value : null) !== null) {
                return false;
            }
        }

        return true;
    }
}
