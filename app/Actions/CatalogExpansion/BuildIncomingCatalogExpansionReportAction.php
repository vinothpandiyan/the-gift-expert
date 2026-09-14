<?php

namespace App\Actions\CatalogExpansion;

use App\Actions\Catalog\AnalyzeCatalogCoverageAction;
use App\Actions\CatalogCuration\ResolveAcceptedCurationAuditRunAction;
use App\CatalogCoverage\CatalogCoverageOptions;
use App\CatalogCoverage\DimensionCoverage;
use App\Enums\IncomingCatalogContribution;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\ReplacementAdvice;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use Illuminate\Support\Collection;

class BuildIncomingCatalogExpansionReportAction
{
    /**
     * @var array<string, list<string>>
     */
    private const KNOWN_HOLES = [
        'critical' => ['Father'],
        'high' => ['Gift Cards', 'Digital / Instant Gifts', 'Digital / Instant', 'Experience Gifts'],
        'medium' => [
            'Parents',
            'Boss',
            'Grandparents',
            "Mother's Day",
            'Raksha Bandhan',
            'Baby Shower',
            'Get Well Soon',
            'Pet Parent',
            'Eco-Conscious',
            'Gardening',
            'Premium GiftIntent',
            'Experience GiftIntent',
        ],
    ];

    public function __construct(
        private AnalyzeCatalogCoverageAction $analyzeCoverage,
        private AssessIncomingProductGapContributionAction $assessContribution,
        private CompareReplacementCandidateAction $compareReplacement,
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    public function execute(array $productIds): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $productIds)));
        $products = Product::query()->whereKey($ids)->orderBy('id')->get();
        $holes = $this->openHoles();
        $acceptedRun = $this->resolveAcceptedRun->execute();
        $removeCandidates = $this->removeCandidates();

        $rows = [];
        $comparisons = [];

        foreach ($products as $product) {
            $audit = $this->latestCompletedAudit($product->id);
            $contributions = $this->assessContribution->execute($product, $audit, $holes);

            $rows[] = [
                'product_id' => (int) $product->id,
                'title' => (string) $product->name,
                'status' => $product->status?->value,
                'published_at' => $product->published_at?->toIso8601String(),
                'classification_status' => $product->taxonomy_classification_status?->value,
                'concept_key' => $audit?->concept_key,
                'gift_score' => $audit?->gift_score,
                'catalog_value' => $audit?->catalog_value_score,
                'gift_intents' => $audit?->gift_intents,
                'contributions' => array_map(
                    fn (IncomingCatalogContribution $contribution): string => $contribution->value,
                    $contributions,
                ),
                'father_assigned' => $product->relationships()->whereRaw('LOWER(name) = ?', ['father'])->exists(),
                'gift_card_assigned' => $product->giftTypes()
                    ->whereRaw('LOWER(name) IN (?, ?, ?)', ['gift cards', 'digital / instant gifts', 'digital / instant'])
                    ->exists(),
                'experience_gift_assigned' => $product->giftTypes()->whereRaw('LOWER(name) = ?', ['experience gifts'])->exists(),
            ];

            if (! is_string($audit?->concept_key) || $audit->concept_key === '') {
                continue;
            }

            foreach ($removeCandidates as $existing) {
                if ((int) $existing->id === (int) $product->id) {
                    continue;
                }

                $existingAudit = $this->acceptedOrLatestAudit((int) $existing->id, $acceptedRun);

                if (! $existingAudit instanceof ProductCurationAudit) {
                    continue;
                }

                if ($existingAudit->concept_key !== $audit->concept_key) {
                    continue;
                }

                $comparison = $this->compareReplacement->execute($product, $audit, $existing, $existingAudit);
                $comparisons[] = [
                    'advice' => $comparison['advice'] instanceof ReplacementAdvice
                        ? $comparison['advice']->value
                        : $comparison['advice'],
                    'incoming' => $comparison['incoming'],
                    'existing' => $comparison['existing'],
                    'archived' => $comparison['archived'],
                    'existing_status' => $existing->fresh()->status?->value,
                ];
            }
        }

        return [
            'product_count' => $products->count(),
            'coverage_holes' => $holes,
            'products' => $rows,
            'replacement_comparisons' => $comparisons,
            'father_fit' => collect($rows)->contains(fn (array $row): bool => $row['father_assigned'] === true),
            'gift_card_fit' => collect($rows)->contains(fn (array $row): bool => $row['gift_card_assigned'] === true),
            'experience_gift_fit' => collect($rows)->contains(fn (array $row): bool => $row['experience_gift_assigned'] === true),
            'published_mutated' => $products->contains(fn (Product $product): bool => $product->status === ProductStatus::Published),
            'archived_mutated' => $products->contains(fn (Product $product): bool => $product->status === ProductStatus::Archived)
                || collect($comparisons)->contains(fn (array $row): bool => $row['archived'] === true || $row['existing_status'] === ProductStatus::Archived->value),
        ];
    }

    /**
     * @return array<string, list<array{name: string}>>
     */
    private function openHoles(): array
    {
        $coverage = $this->analyzeCoverage->execute(new CatalogCoverageOptions(publishedOnly: true));
        $zeroNames = collect($coverage->dimensionCoverage)
            ->filter(fn (DimensionCoverage $row): bool => $row->publishedCount === 0)
            ->map(fn (DimensionCoverage $row): string => strtolower($row->name))
            ->all();

        $holes = ['critical' => [], 'high' => [], 'medium' => []];

        foreach (self::KNOWN_HOLES as $level => $names) {
            foreach ($names as $name) {
                $needle = strtolower(preg_replace('/\s+giftintent$/', '', $name) ?? $name);

                if (in_array(strtolower($name), $zeroNames, true) || in_array($needle, $zeroNames, true) || str_ends_with(strtolower($name), 'giftintent')) {
                    $holes[$level][] = ['name' => $name];
                }
            }
        }

        return $holes;
    }

    /**
     * @return Collection<int, Product>
     */
    private function removeCandidates()
    {
        $ids = ProductCurationDecisionRecord::query()
            ->current()
            ->where('decision', ProductCurationDecision::RemoveCandidate)
            ->pluck('product_id');

        return Product::query()->whereKey($ids)->orderBy('id')->get();
    }

    private function latestCompletedAudit(int $productId): ?ProductCurationAudit
    {
        return ProductCurationAudit::query()
            ->where('product_id', $productId)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->orderByDesc('id')
            ->first();
    }

    private function acceptedOrLatestAudit(int $productId, ?ProductCurationAuditRun $acceptedRun): ?ProductCurationAudit
    {
        if ($acceptedRun instanceof ProductCurationAuditRun) {
            $accepted = ProductCurationAudit::query()
                ->where('run_id', $acceptedRun->id)
                ->where('product_id', $productId)
                ->where('outcome', ProductCurationAuditOutcome::Completed)
                ->first();

            if ($accepted instanceof ProductCurationAudit) {
                return $accepted;
            }
        }

        return $this->latestCompletedAudit($productId);
    }
}
