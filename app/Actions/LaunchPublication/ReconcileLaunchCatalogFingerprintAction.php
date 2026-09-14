<?php

namespace App\Actions\LaunchPublication;

use App\LaunchPublication\LaunchCatalogFingerprint;
use App\LaunchPublication\LaunchPublicationAttempt;

class ReconcileLaunchCatalogFingerprintAction
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PRODUCT_COLUMNS = [
        'status',
        'published_at',
        'editorial_ownership',
        'editorial_generation_version',
        'editorial_reviewed_at',
        'updated_at',
    ];

    /**
     * @param  list<LaunchPublicationAttempt>  $attempts
     * @return array{
     *     published_ids: list<int>,
     *     explained: list<array<string, mixed>>,
     *     unexplained: list<array<string, mixed>>,
     *     hashes: array{before: string, after: string, changed: bool}
     * }
     */
    public function execute(
        LaunchCatalogFingerprint $before,
        LaunchCatalogFingerprint $after,
        iterable $attempts,
    ): array {
        $publishedIds = [];

        foreach ($attempts as $attempt) {
            if ($attempt->result->value === 'published') {
                $publishedIds[] = $attempt->productId;
            }
        }

        $publishedIds = array_values(array_unique($publishedIds));
        $explained = [];
        $unexplained = [];

        foreach ($after->tables as $table => $afterRows) {
            $beforeRows = $before->tables[$table] ?? [];

            if ($table === 'products') {
                $this->diffKeyed(
                    table: $table,
                    beforeRows: $beforeRows,
                    afterRows: $afterRows,
                    key: 'id',
                    publishedIds: $publishedIds,
                    explained: $explained,
                    unexplained: $unexplained,
                    allowedColumns: self::ALLOWED_PRODUCT_COLUMNS,
                    productIdColumn: 'id',
                );

                continue;
            }

            if ($table === 'category_product') {
                $this->diffPivots(
                    table: $table,
                    beforeRows: $beforeRows,
                    afterRows: $afterRows,
                    publishedIds: $publishedIds,
                    explained: $explained,
                    unexplained: $unexplained,
                );

                continue;
            }

            if ($beforeRows !== $afterRows) {
                $unexplained[] = [
                    'table' => $table,
                    'reason' => 'unexpected_table_mutation',
                ];
            }
        }

        return [
            'published_ids' => $publishedIds,
            'explained' => $explained,
            'unexplained' => $unexplained,
            'hashes' => [
                'before' => $before->hash,
                'after' => $after->hash,
                'changed' => $before->hash !== $after->hash,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $beforeRows
     * @param  list<array<string, mixed>>  $afterRows
     * @param  list<int>  $publishedIds
     * @param  list<array<string, mixed>>  $explained
     * @param  list<array<string, mixed>>  $unexplained
     * @param  list<string>  $allowedColumns
     */
    private function diffKeyed(
        string $table,
        array $beforeRows,
        array $afterRows,
        string $key,
        array $publishedIds,
        array &$explained,
        array &$unexplained,
        array $allowedColumns,
        string $productIdColumn,
    ): void {
        $beforeMap = collect($beforeRows)->keyBy($key);
        $afterMap = collect($afterRows)->keyBy($key);

        foreach ($afterMap as $id => $afterRow) {
            $beforeRow = $beforeMap->get($id);

            if ($beforeRow === null) {
                $unexplained[] = [
                    'table' => $table,
                    'key' => $id,
                    'reason' => 'unexpected_insert',
                ];

                continue;
            }

            if ($beforeRow === $afterRow) {
                continue;
            }

            $changed = [];
            foreach ($afterRow as $column => $value) {
                if (($beforeRow[$column] ?? null) !== $value) {
                    $changed[] = $column;
                }
            }

            $productId = (int) ($afterRow[$productIdColumn] ?? 0);
            $disallowed = array_values(array_diff($changed, $allowedColumns));

            if (in_array($productId, $publishedIds, true) && $disallowed === []) {
                $explained[] = [
                    'table' => $table,
                    'key' => $id,
                    'product_id' => $productId,
                    'changed_columns' => $changed,
                ];

                continue;
            }

            $unexplained[] = [
                'table' => $table,
                'key' => $id,
                'product_id' => $productId,
                'changed_columns' => $changed,
                'reason' => in_array($productId, $publishedIds, true)
                    ? 'disallowed_column_change'
                    : 'mutation_outside_manifest',
            ];
        }

        foreach ($beforeMap as $id => $beforeRow) {
            if (! $afterMap->has($id)) {
                $unexplained[] = [
                    'table' => $table,
                    'key' => $id,
                    'reason' => 'unexpected_delete',
                ];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $beforeRows
     * @param  list<array<string, mixed>>  $afterRows
     * @param  list<int>  $publishedIds
     * @param  list<array<string, mixed>>  $explained
     * @param  list<array<string, mixed>>  $unexplained
     */
    private function diffPivots(
        string $table,
        array $beforeRows,
        array $afterRows,
        array $publishedIds,
        array &$explained,
        array &$unexplained,
    ): void {
        $serialize = fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR);
        $beforeSet = collect($beforeRows)->map($serialize);
        $afterSet = collect($afterRows)->map($serialize);

        $removed = $beforeSet->diff($afterSet);
        $added = $afterSet->diff($beforeSet);

        foreach ($removed as $rowJson) {
            $row = json_decode((string) $rowJson, true);
            $productId = (int) ($row['product_id'] ?? 0);

            if (in_array($productId, $publishedIds, true)) {
                $explained[] = [
                    'table' => $table,
                    'product_id' => $productId,
                    'change' => 'removed',
                    'row' => $row,
                ];

                continue;
            }

            $unexplained[] = [
                'table' => $table,
                'product_id' => $productId,
                'change' => 'removed',
                'row' => $row,
                'reason' => 'taxonomy_mutation_outside_manifest',
            ];
        }

        foreach ($added as $rowJson) {
            $row = json_decode((string) $rowJson, true);
            $productId = (int) ($row['product_id'] ?? 0);

            if (in_array($productId, $publishedIds, true)) {
                $explained[] = [
                    'table' => $table,
                    'product_id' => $productId,
                    'change' => 'added',
                    'row' => $row,
                ];

                continue;
            }

            $unexplained[] = [
                'table' => $table,
                'product_id' => $productId,
                'change' => 'added',
                'row' => $row,
                'reason' => 'taxonomy_mutation_outside_manifest',
            ];
        }
    }
}
