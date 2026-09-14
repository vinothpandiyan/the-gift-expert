<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\HumanCurationReviewCase;
use App\Enums\ProductCurationAuditOutcome;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision;

class BuildHumanCurationReviewAction
{
    public function __construct(
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
        private ResolveProductCurationPriorityAction $resolvePriority,
        private ResolveEffectiveProductCurationDecisionAction $resolveDecision,
        private ResolveCurationReviewProgressAction $resolveProgress,
        private ResolveProductConceptPeersAction $resolvePeers,
        private BuildCurationReviewReasonsAction $reviewReasons,
        private BuildProductCurationAuditReviewAction $auditReview,
        private DiagnoseP0IntegrityAction $diagnoseP0,
        private BuildP2TaxonomyReviewAction $taxonomyReview,
        private ResolveP3QualitySubgroupAction $qualitySubgroup,
        private BuildProductCurationEvidenceAction $buildEvidence,
    ) {}

    public function execute(Product $product, ?ProductCurationAuditRun $run = null): ?HumanCurationReviewCase
    {
        $product->loadMissing([
            'images',
            'affiliateLinks.merchant',
            'affiliateLinks.catalogProductSources.sourceList',
            'categories',
            'relationships',
            'occasions',
            'interests',
            'giftTypes',
            'recipientTypes',
            'professions',
            'currentCurationDecision.decidedBy',
            'curationDecisions.decidedBy',
            'curationDecisions.sourceAuditRun',
        ]);

        $run ??= $this->resolveAcceptedRun->execute();

        if (! $run instanceof ProductCurationAuditRun) {
            return null;
        }

        $audit = ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->where('product_id', $product->id)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->first();

        if (! $audit instanceof ProductCurationAudit) {
            return null;
        }

        $latestAudit = $product->latestCompletedCurationAudit ?? $audit;
        $currentDecision = $this->resolveDecision->execute($product);
        $peers = $this->resolvePeers->execute($product, $audit, $run);
        $product->setRelation('latestCompletedCurationAudit', $audit);
        $review = $this->auditReview->execute($product);

        if ($review === null) {
            return null;
        }

        $relativeCoverage = is_array(data_get($audit->catalog_context_snapshot, 'relative_coverage'))
            ? data_get($audit->catalog_context_snapshot, 'relative_coverage')
            : null;

        return new HumanCurationReviewCase(
            product: $product,
            run: $run,
            audit: $audit,
            latestAudit: $latestAudit,
            priority: $this->resolvePriority->execute($audit),
            currentDecision: $currentDecision,
            isHumanReviewed: $currentDecision instanceof ProductCurationDecision && $currentDecision->isHumanReviewed(),
            recommendationDisagrees: $currentDecision instanceof ProductCurationDecision
                && $latestAudit->recommendation?->value !== null
                && $currentDecision->decision->value !== $latestAudit->recommendation->value,
            reviewReasons: $this->reviewReasons->execute($audit),
            auditReview: $review,
            giftFactors: $review->giftFactors,
            catalogFactors: $review->catalogFactors,
            relativeCoverage: $relativeCoverage,
            conceptKey: $audit->concept_key,
            conceptLabel: $audit->concept_label,
            conceptProgress: filled($audit->concept_key)
                ? $this->resolveProgress->forConcept((string) $audit->concept_key, $run)
                : null,
            currentPeer: $peers['current'],
            peers: $peers['peers'],
            hiddenPeerCount: $peers['remaining'],
            history: $product->curationDecisions->all(),
            productSummary: $this->productSummary($product),
            classification: $this->classification($product),
            evidence: $this->evidence($audit, $product),
            integrityDiagnosis: $this->resolvePriority->isIntegrityProblem($audit)
                ? $this->diagnoseP0->execute($product, $audit)
                : null,
            taxonomyReview: $this->taxonomyReview->execute($product, $audit),
            qualitySubgroup: $this->qualitySubgroup->execute($audit),
            strongestFits: $this->strongestFits($audit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $product): array
    {
        $image = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $link = $product->affiliateLinks->firstWhere('is_primary', true) ?? $product->affiliateLinks->first();
        $sources = $link?->catalogProductSources ?? collect();

        return [
            'image_url' => $image?->url(),
            'title' => $product->name,
            'price' => $product->price_amount !== null
                ? ($product->price_currency ?? 'INR').' '.$product->price_amount
                : null,
            'merchant' => $link?->merchant?->name,
            'status' => $product->status?->value,
            'affiliate' => $link?->external_product_id,
            'availability' => $link?->availability,
            'last_seen_at' => $link?->last_seen_at?->toIso8601String(),
            'last_verified_at' => $link?->last_verified_at?->toIso8601String(),
            'provenance' => $sources
                ->map(fn ($source): string => (string) ($source->sourceList?->name ?: $source->sourceList?->kind?->value ?: 'source'))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classification(Product $product): array
    {
        return [
            'primary_category' => $product->categories->firstWhere('pivot.is_primary', true)?->name
                ?? $product->categories->first()?->name,
            'relationships' => $product->relationships->pluck('name')->all(),
            'occasions' => $product->occasions->pluck('name')->all(),
            'interests' => $product->interests->pluck('name')->all(),
            'gift_types' => $product->giftTypes->pluck('name')->all(),
            'recipient_types' => $product->recipientTypes->pluck('name')->all(),
            'professions' => $product->professions->pluck('name')->all(),
            'classification_status' => $product->taxonomy_classification_status?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strongestFits(ProductCurationAudit $audit): array
    {
        $fits = is_array($audit->strongest_fits) ? $audit->strongest_fits : [];
        $labels = [
            'relationships' => 'Relationship',
            'occasions' => 'Occasion',
            'interests' => 'Interest',
            'gift_types' => 'GiftType',
        ];
        $strongest = [];

        foreach ($labels as $dimension => $label) {
            $fit = $fits[$dimension] ?? null;

            $strongest[$dimension] = [
                'label' => $label,
                'name' => is_array($fit) ? (string) ($fit['name'] ?? '') : '',
                'strength' => is_array($fit) ? (string) ($fit['strength'] ?? '') : '',
            ];
        }

        return $strongest;
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(ProductCurationAudit $audit, Product $product): array
    {
        $snapshot = is_array($audit->evidence_snapshot) ? $audit->evidence_snapshot : [];
        $offers = collect($snapshot['offers'] ?? []);
        $images = collect($snapshot['images'] ?? []);
        $live = $this->buildEvidence->execute($product);
        $currentOffer = collect($live->offers)->firstWhere('is_primary', true)
            ?? collect($live->offers)->first();

        return [
            'price' => data_get($snapshot, 'price.amount'),
            'currency' => data_get($snapshot, 'price.currency'),
            'offer_freshness' => $offers
                ->pluck('last_seen_at')
                ->filter()
                ->sortDesc()
                ->first(),
            'has_primary_image' => $images->contains(fn (mixed $image): bool => is_array($image) && ($image['is_primary'] ?? false) === true),
            'provenance_count' => count($snapshot['provenance'] ?? []),
            'missing' => collect($audit->issues ?? [])
                ->filter(fn (mixed $issue): bool => is_array($issue) && ($issue['code'] ?? null) === 'missing_commerce_evidence')
                ->flatMap(fn (array $issue): array => (array) data_get($issue, 'context.missing', []))
                ->values()
                ->all(),
            'current' => [
                'price' => $live->priceAmount,
                'currency' => $live->priceCurrency,
                'availability' => is_array($currentOffer) ? ($currentOffer['availability'] ?? null) : null,
                'last_seen_at' => is_array($currentOffer) ? ($currentOffer['last_seen_at'] ?? null) : null,
                'last_verified_at' => is_array($currentOffer) ? ($currentOffer['last_verified_at'] ?? null) : null,
                'merchant' => is_array($currentOffer) ? data_get($currentOffer, 'merchant.name') : null,
                'external_product_id' => is_array($currentOffer) ? ($currentOffer['external_product_id'] ?? null) : null,
                'status' => is_array($currentOffer) ? ($currentOffer['status'] ?? null) : null,
                'exact_identity_present' => is_array($currentOffer) && filled($currentOffer['external_product_id'] ?? null),
                'provenance_last_seen_at' => collect($live->provenance)
                    ->pluck('last_seen_at')
                    ->filter()
                    ->sortDesc()
                    ->first(),
            ],
        ];
    }
}
