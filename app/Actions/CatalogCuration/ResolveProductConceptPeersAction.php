<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductConceptPeer;
use App\Enums\ProductCurationAuditOutcome;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Support\Collection;

class ResolveProductConceptPeersAction
{
    public function __construct(
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
    ) {}

    /**
     * @return array{concept_key: ?string, concept_label: ?string, current: ?ProductConceptPeer, peers: list<ProductConceptPeer>, remaining: int}
     */
    public function execute(
        Product $product,
        ?ProductCurationAudit $audit = null,
        ?ProductCurationAuditRun $run = null,
        ?int $limit = null,
    ): array {
        $run ??= $audit?->run ?? $this->resolveAcceptedRun->execute();
        $limit ??= (int) config('catalog_curation.human_curation.peer_comparison_limit', 8);
        $audit ??= $this->auditFor($product, $run);

        if (! $audit instanceof ProductCurationAudit || blank($audit->concept_key) || ! $run instanceof ProductCurationAuditRun) {
            return [
                'concept_key' => $audit?->concept_key,
                'concept_label' => $audit?->concept_label,
                'current' => $audit instanceof ProductCurationAudit ? $this->peerFromAudit($audit, true) : null,
                'peers' => [],
                'remaining' => 0,
            ];
        }

        $cluster = ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->where('concept_key', $audit->concept_key)
            ->with([
                'product.currentCurationDecision',
                'product.images',
            ])
            ->orderBy('product_id')
            ->get();

        $current = $cluster->firstWhere('product_id', $product->id) ?? $audit;
        $others = $cluster
            ->filter(fn (ProductCurationAudit $candidate): bool => (int) $candidate->product_id !== (int) $product->id)
            ->values();
        $visible = $others->take($limit);

        return [
            'concept_key' => $audit->concept_key,
            'concept_label' => $audit->concept_label,
            'current' => $this->peerFromAudit($current, true),
            'peers' => $visible
                ->map(fn (ProductCurationAudit $candidate): ProductConceptPeer => $this->peerFromAudit($candidate, false))
                ->all(),
            'remaining' => max(0, $others->count() - $visible->count()),
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return list<ProductConceptPeer>
     */
    public function forComparison(ProductCurationAuditRun $run, array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($ids === []) {
            return [];
        }

        return ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->whereIn('product_id', $ids)
            ->with([
                'product.currentCurationDecision',
                'product.images',
                'product.relationships',
                'product.occasions',
                'product.interests',
                'product.giftTypes',
            ])
            ->get()
            ->sortBy(fn (ProductCurationAudit $audit): int => array_search((int) $audit->product_id, $ids, true) ?: 0)
            ->map(fn (ProductCurationAudit $audit): ProductConceptPeer => $this->peerFromAudit($audit, false))
            ->values()
            ->all();
    }

    private function auditFor(Product $product, ?ProductCurationAuditRun $run): ?ProductCurationAudit
    {
        if (! $run instanceof ProductCurationAuditRun) {
            return $product->latestCompletedCurationAudit;
        }

        return ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->where('product_id', $product->id)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->first();
    }

    private function peerFromAudit(ProductCurationAudit $audit, bool $isCurrent): ProductConceptPeer
    {
        $product = $audit->product;
        $image = $product?->images?->firstWhere('is_primary', true) ?? $product?->images?->first();
        $price = $audit->evidence_snapshot['price']['amount'] ?? $product?->price_amount;
        $currency = $audit->evidence_snapshot['price']['currency'] ?? $product?->price_currency ?? 'INR';

        return new ProductConceptPeer(
            productId: (int) $audit->product_id,
            title: (string) ($product?->name ?: data_get($audit->evidence_snapshot, 'name', 'Untitled gift')),
            imageUrl: $image?->url(),
            price: $price !== null ? $currency.' '.$price : null,
            giftScore: $audit->gift_score,
            catalogValue: $audit->catalog_value_score,
            differentiation: $audit->differentiation_factor,
            budgetBand: data_get($audit->catalog_context_snapshot, 'price_band'),
            taxonomyContext: $this->taxonomyContext($audit),
            giftIntents: array_values(array_filter((array) $audit->gift_intents, fn (mixed $intent): bool => is_string($intent))),
            humanDecision: $product?->currentCurationDecision?->decision,
            humanRole: $product?->currentCurationDecision?->catalog_role,
            isCurrent: $isCurrent,
        );
    }

    /**
     * @return list<string>
     */
    private function taxonomyContext(ProductCurationAudit $audit): array
    {
        $strongest = is_array($audit->strongest_fits) ? $audit->strongest_fits : [];

        $labels = collect($strongest)
            ->flatten(1)
            ->filter(fn (mixed $fit): bool => is_array($fit) && filled($fit['name'] ?? null))
            ->map(fn (array $fit): string => (string) $fit['name'])
            ->unique()
            ->take(4)
            ->values();

        if ($labels->isNotEmpty()) {
            return $labels->all();
        }

        return collect(['relationships', 'occasions', 'interests', 'gift_types'])
            ->flatMap(fn (string $dimension): Collection => collect(data_get($audit->evidence_snapshot, "taxonomy.{$dimension}", [])))
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item): string => (string) $item['name'])
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }
}
