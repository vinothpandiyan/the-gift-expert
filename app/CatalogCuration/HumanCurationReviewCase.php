<?php

namespace App\CatalogCuration;

use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationPriority;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision;

readonly class HumanCurationReviewCase
{
    /**
     * @param  list<string>  $reviewReasons
     * @param  list<array{label: string, score: string, detail: ?string}>  $giftFactors
     * @param  list<array{label: string, score: string, detail: ?string}>  $catalogFactors
     * @param  array<string, mixed>|null  $relativeCoverage
     * @param  list<ProductConceptPeer>  $peers
     * @param  list<ProductCurationDecision>  $history
     * @param  array<string, mixed>  $productSummary
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $strongestFits
     */
    public function __construct(
        public Product $product,
        public ProductCurationAuditRun $run,
        public ProductCurationAudit $audit,
        public ProductCurationAudit $latestAudit,
        public ProductCurationPriority $priority,
        public ?ProductCurationDecision $currentDecision,
        public bool $isHumanReviewed,
        public bool $recommendationDisagrees,
        public array $reviewReasons,
        public ProductCurationAuditReview $auditReview,
        public array $giftFactors,
        public array $catalogFactors,
        public ?array $relativeCoverage,
        public ?string $conceptKey,
        public ?string $conceptLabel,
        public ?ConceptCurationProgress $conceptProgress,
        public ?ProductConceptPeer $currentPeer,
        public array $peers,
        public int $hiddenPeerCount,
        public array $history,
        public array $productSummary,
        public array $classification,
        public array $evidence,
        public ?P0IntegrityDiagnosisResult $integrityDiagnosis,
        public ?P2TaxonomyReview $taxonomyReview,
        public ?P3QualitySubgroup $qualitySubgroup = null,
        public array $strongestFits = [],
    ) {}
}
