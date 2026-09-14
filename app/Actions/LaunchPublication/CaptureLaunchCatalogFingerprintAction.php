<?php

namespace App\Actions\LaunchPublication;

use App\LaunchPublication\LaunchCatalogFingerprint;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision;
use Illuminate\Support\Facades\DB;

class CaptureLaunchCatalogFingerprintAction
{
    /**
     * @var list<string>
     */
    private const PRODUCT_COLUMNS = [
        'id',
        'name',
        'slug',
        'status',
        'published_at',
        'price_amount',
        'price_currency',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'editorial_ownership',
        'editorial_generation_version',
        'editorial_reviewed_at',
        'seo_ownership',
        'taxonomy_classification_status',
        'taxonomy_classified_at',
        'taxonomy_content_fingerprint',
        'taxonomy_relationship_hint_fingerprint',
        'taxonomy_classification_version',
        'taxonomy_proposal_pending',
        'deleted_at',
        'updated_at',
    ];

    public function execute(): LaunchCatalogFingerprint
    {
        $tables = [
            'products' => $this->rows('products', self::PRODUCT_COLUMNS, 'id'),
            'category_product' => $this->pivot('category_product', ['product_id', 'category_id', 'is_primary']),
            'relationship_product' => $this->pivot('relationship_product', ['product_id', 'relationship_id']),
            'occasion_product' => $this->pivot('occasion_product', ['product_id', 'occasion_id']),
            'interest_product' => $this->pivot('interest_product', ['product_id', 'interest_id']),
            'gift_type_product' => $this->pivot('gift_type_product', ['product_id', 'gift_type_id']),
            'recipient_type_product' => $this->pivot('recipient_type_product', ['product_id', 'recipient_type_id']),
            'profession_product' => $this->pivot('profession_product', ['product_id', 'profession_id']),
            'product_images' => $this->rows('product_images', ['id', 'product_id', 'path', 'is_primary', 'sort_order'], 'id'),
            'affiliate_links' => $this->rows('affiliate_links', ['id', 'product_id', 'merchant_id', 'url', 'external_product_id', 'status', 'is_primary', 'availability', 'deleted_at'], 'id'),
            'product_curation_decisions' => $this->rows('product_curation_decisions', ['id', 'product_id', 'decision', 'catalog_role', 'current_for_product_id', 'reason_codes', 'reason_notes', 'remediation_status'], 'id'),
            'product_curation_audits' => $this->rows('product_curation_audits', ['id', 'run_id', 'product_id', 'outcome', 'gift_score', 'catalog_value_score', 'semantic_fingerprint', 'evidence_fingerprint'], 'id'),
        ];

        $counts = [
            'products_including_trash' => (int) DB::table('products')->count(),
            'products_soft_deleted' => (int) DB::table('products')->whereNotNull('deleted_at')->count(),
            'draft' => (int) DB::table('products')->whereNull('deleted_at')->where('status', 'draft')->count(),
            'published' => (int) DB::table('products')->whereNull('deleted_at')->where('status', 'published')->count(),
            'archived' => (int) DB::table('products')->whereNull('deleted_at')->where('status', 'archived')->count(),
            'human_decisions' => ProductCurationDecision::query()->count(),
            'current_human_decisions' => ProductCurationDecision::query()->current()->count(),
            'audit_runs' => ProductCurationAuditRun::query()->count(),
            'audit_rows' => ProductCurationAudit::query()->count(),
            'classification' => DB::table('products')
                ->whereNull('deleted_at')
                ->select('taxonomy_classification_status', DB::raw('count(*) as aggregate'))
                ->groupBy('taxonomy_classification_status')
                ->pluck('aggregate', 'taxonomy_classification_status')
                ->map(fn (mixed $count): int => (int) $count)
                ->all(),
            'current_decisions' => ProductCurationDecision::query()
                ->current()
                ->select('decision', DB::raw('count(*) as aggregate'))
                ->groupBy('decision')
                ->pluck('aggregate', 'decision')
                ->map(fn (mixed $count): int => (int) $count)
                ->all(),
        ];

        $hash = hash('sha256', json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return new LaunchCatalogFingerprint($counts, $tables, $hash);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $columns, string $orderBy): array
    {
        return DB::table($table)
            ->select($columns)
            ->orderBy($orderBy)
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function pivot(string $table, array $columns): array
    {
        $query = DB::table($table)->select($columns);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return $query
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }
}
