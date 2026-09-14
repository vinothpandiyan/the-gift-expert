<?php

namespace App\Actions\LaunchPublication;

use App\Actions\CatalogCuration\ResolveEffectiveProductCurationDecisionAction;
use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Collection;

class InspectPublishedCatalogAction
{
    public function __construct(
        private AssessProductPublicationRequirementsAction $assessPublication,
        private ResolveEffectiveProductCurationDecisionAction $resolveDecision,
    ) {}

    /**
     * @return array{
     *     count: int,
     *     products: list<array<string, mixed>>,
     *     anomalies: list<array<string, mixed>>,
     *     remove_candidate_published: list<array<string, mixed>>,
     *     decision_counts: array<string, int>
     * }
     */
    public function execute(): array
    {
        $products = Product::query()
            ->published()
            ->with('currentCurationDecision')
            ->orderBy('id')
            ->get();

        $rows = [];
        $anomalies = [];
        $removeCandidatePublished = [];
        $decisionCounts = ['none' => 0];

        foreach ($products as $product) {
            $decision = $this->resolveDecision->execute($product);
            $decisionValue = $decision?->decision?->value ?? 'none';
            $decisionCounts[$decisionValue] = ($decisionCounts[$decisionValue] ?? 0) + 1;

            $assessment = $this->assessPublication->execute($product);
            $row = [
                'product_id' => (int) $product->id,
                'title' => (string) $product->name,
                'status' => $product->status?->value,
                'archived' => $product->status === ProductStatus::Archived,
                'human_decision' => $decision?->decision?->value,
                'human_merchandising_role' => $decision?->catalog_role?->value,
                'classification' => $product->taxonomy_classification_status?->value,
                'price_amount' => $product->price_amount !== null ? (string) $product->price_amount : null,
                'gate_ready' => $assessment['error_codes'] === [],
                'blocking_codes' => $assessment['error_codes'],
                'blocking_reasons' => $assessment['error_messages'],
            ];
            $rows[] = $row;

            $flags = [];

            if ($assessment['error_codes'] !== []) {
                $flags[] = 'publication_gate_violation';
            }

            if ($decision?->decision === ProductCurationDecision::Deactivate) {
                $flags[] = 'human_deactivate';
            }

            if ($decision?->decision === ProductCurationDecision::Defer) {
                $flags[] = 'human_defer';
            }

            if ($decision?->decision === ProductCurationDecision::Reclassify) {
                $flags[] = 'human_reclassify';
            }

            if ($decision?->decision === ProductCurationDecision::RemoveCandidate) {
                $flags[] = 'human_remove_candidate';
                $removeCandidatePublished[] = $row;
            }

            if ($flags !== []) {
                $anomalies[] = $row + ['flags' => $flags];
            }
        }

        ksort($decisionCounts);

        return [
            'count' => $products->count(),
            'products' => $rows,
            'anomalies' => $anomalies,
            'remove_candidate_published' => $removeCandidatePublished,
            'decision_counts' => $decisionCounts,
        ];
    }

    /**
     * @return Collection<int, Product>
     */
    public function featureProducts(): Collection
    {
        return Product::query()
            ->whereHas('currentCurationDecision', function ($query): void {
                $query->where('decision', ProductCurationDecision::Feature);
            })
            ->with('currentCurationDecision')
            ->orderBy('id')
            ->get();
    }
}
