<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\CuratedIntakeAuditReport;
use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\Category;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AuditCuratedIntakeRunAction
{
    public function __construct(
        private QueryProductsForCuratedClassificationAction $queryProducts,
        private ShouldReclassifyCuratedMerchantProductAction $shouldReclassify,
        private IsAcceptableMerchandisingCategoryAction $isAcceptableMerchandisingCategory,
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
    ) {}

    public function execute(int $intakeRunId): CuratedIntakeAuditReport
    {
        $run = CuratedProductIntakeRun::query()->find($intakeRunId);

        if (! $run instanceof CuratedProductIntakeRun) {
            throw new InvalidArgumentException("Intake run [{$intakeRunId}] was not found.");
        }

        $items = CuratedProductIntakeItem::query()
            ->where('curated_product_intake_run_id', $intakeRunId)
            ->whereNotNull('product_id')
            ->get();

        $products = $this->queryProducts->execute($intakeRunId)
            ->load([
                'categories.parent',
                'occasions:id,name,slug,is_active',
                'relationships:id,name,slug,is_active',
                'recipientTypes:id,name,slug,is_active',
                'interests:id,name,slug,is_active',
                'professions:id,name,slug,is_active',
                'giftTypes:id,name,slug,is_active',
                'affiliateLinks.catalogProductSources.sourceList.relationship',
            ]);

        $classificationCounts = [
            TaxonomyClassificationStatus::None->value => 0,
            TaxonomyClassificationStatus::AiAccepted->value => 0,
            TaxonomyClassificationStatus::Review->value => 0,
            TaxonomyClassificationStatus::Failed->value => 0,
            TaxonomyClassificationStatus::HumanApproved->value => 0,
            TaxonomyClassificationStatus::HumanOverridden->value => 0,
            TaxonomyClassificationStatus::AiProposed->value => 0,
        ];
        $publicationCounts = [
            ProductStatus::Draft->value => 0,
            ProductStatus::Published->value => 0,
            ProductStatus::Archived->value => 0,
        ];
        $conflictFields = ['title' => 0, 'price' => 0, 'availability' => 0, 'image' => 0];
        $missingPrimary = [];
        $multiplePrimaries = [];
        $missingAncestors = [];
        $semantic = [];
        $provenanceIssues = [];
        $residualNone = [];
        $reviewPriority = [];
        $inactiveHints = [];
        $taxonomyFromNonHintLists = [];
        $provenanceRows = 0;
        $wrongMerchant = 0;
        $multiList = 0;

        foreach ($items as $item) {
            $payload = is_array($item->source_payload) ? $item->source_payload : [];
            $conflicts = is_array($payload['commercial_conflicts'] ?? null) ? $payload['commercial_conflicts'] : [];

            foreach ($conflicts as $field) {
                if (isset($conflictFields[$field])) {
                    $conflictFields[$field]++;
                }
            }
        }

        $categoryNames = Category::query()->pluck('name', 'id');

        foreach ($products as $product) {
            $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;
            $classificationCounts[$status->value] = ($classificationCounts[$status->value] ?? 0) + 1;
            $publicationCounts[$product->status->value] = ($publicationCounts[$product->status->value] ?? 0) + 1;

            $primaries = $product->categories->filter(
                fn ($category): bool => (bool) $category->pivot?->is_primary,
            );
            $appliedStatuses = [
                TaxonomyClassificationStatus::AiAccepted,
                TaxonomyClassificationStatus::HumanApproved,
                TaxonomyClassificationStatus::HumanOverridden,
            ];

            if (in_array($status, $appliedStatuses, true) && $primaries->count() === 0) {
                $missingPrimary[] = $this->productRef($product);
            }

            if ($primaries->count() > 1) {
                $multiplePrimaries[] = $this->productRef($product) + [
                    'primary_category_ids' => $primaries->pluck('id')->all(),
                ];
            }

            foreach ($primaries as $primary) {
                if ($primary->parent_id === null) {
                    continue;
                }

                if (! $this->isAcceptableMerchandisingCategory->execute((int) $primary->parent_id)) {
                    continue;
                }

                $attached = $product->categories->contains(
                    fn ($category): bool => (int) $category->id === (int) $primary->parent_id,
                );

                if (! $attached) {
                    $missingAncestors[] = $this->productRef($product) + [
                        'child' => $primary->name,
                        'expected_ancestor' => $categoryNames[$primary->parent_id] ?? (string) $primary->parent_id,
                    ];
                }
            }

            if (in_array($status, $appliedStatuses, true)) {
                foreach ($this->semanticConflicts->execute($this->taxonomyFromProduct($product)) as $conflict) {
                    $semantic[] = $this->productRef($product) + [
                        'left' => $conflict->leftDimension->value.':'.$conflict->leftId,
                        'right' => $conflict->rightDimension->value.':'.$conflict->rightId,
                    ];
                }
            }

            $sources = $product->affiliateLinks
                ->flatMap(fn ($link) => $link->catalogProductSources);

            $provenanceRows += $sources->count();

            if ($sources->count() > 1) {
                $multiList++;
            }

            $expectedIds = $items
                ->where('product_id', $product->id)
                ->flatMap(fn (CuratedProductIntakeItem $item) => is_array($item->source_list_ids) ? $item->source_list_ids : [])
                ->unique()
                ->values()
                ->all();
            $actualIds = $sources->pluck('catalog_source_list_id')->unique()->values()->all();
            sort($expectedIds);
            $actualSorted = $actualIds;
            sort($actualSorted);

            $missingFromProduct = array_values(array_diff($expectedIds, $actualSorted));

            if ($missingFromProduct !== []) {
                $provenanceIssues[] = $this->productRef($product) + [
                    'expected_source_list_ids' => $expectedIds,
                    'actual_source_list_ids' => $actualSorted,
                    'missing_source_list_ids' => $missingFromProduct,
                ];
            }

            foreach ($product->affiliateLinks as $link) {
                foreach ($link->catalogProductSources as $source) {
                    $list = $source->sourceList;

                    if ($list === null) {
                        continue;
                    }

                    if ((int) $list->merchant_id !== (int) $link->merchant_id) {
                        $wrongMerchant++;
                    }

                    if (
                        $list->kind === CatalogSourceListKind::RecipientHint
                        && $list->relationship_id !== null
                        && $list->relationship !== null
                        && $list->relationship->is_active !== true
                    ) {
                        $inactiveHints[] = $this->productRef($product) + [
                            'relationship' => $list->relationship->name,
                        ];
                    }

                    if (
                        in_array($list->kind, [
                            CatalogSourceListKind::QuarterlyArchive,
                            CatalogSourceListKind::UnclassifiedInbox,
                        ], true)
                        && $list->relationship_id !== null
                    ) {
                        $taxonomyFromNonHintLists[] = $this->productRef($product) + [
                            'source_list' => $list->name,
                        ];
                    }
                }
            }

            if ($status === TaxonomyClassificationStatus::None) {
                $decision = $this->shouldReclassify->execute($product);
                $residualNone[] = $this->productRef($product) + [
                    'explanation' => $decision->shouldReclassify
                        ? 'queued/not processed'
                        : $decision->reason,
                ];
            }

            if ($status === TaxonomyClassificationStatus::Failed) {
                $reviewPriority[] = $this->productRef($product) + [
                    'priority' => 1,
                    'reasons' => is_array($product->taxonomy_review_reasons) ? $product->taxonomy_review_reasons : [],
                ];
            }

            if ($status === TaxonomyClassificationStatus::Review) {
                $reviewPriority[] = $this->productRef($product) + [
                    'priority' => $this->reviewPriority($product),
                    'reasons' => is_array($product->taxonomy_review_reasons) ? $product->taxonomy_review_reasons : [],
                ];
            }
        }

        usort(
            $reviewPriority,
            fn (array $left, array $right): int => $left['priority'] <=> $right['priority'],
        );

        return new CuratedIntakeAuditReport(
            intakeRunId: $intakeRunId,
            uniqueProducts: $products->count(),
            newProducts: (int) $run->items_created,
            existingProducts: (int) $run->items_updated,
            multiListProducts: $multiList,
            provenanceRows: $provenanceRows,
            wrongMerchantProvenance: $wrongMerchant,
            classificationCounts: $classificationCounts,
            publicationCounts: $publicationCounts,
            commercialConflictFieldCounts: $conflictFields,
            missingPrimaryCategory: $missingPrimary,
            multiplePrimaryCategories: $multiplePrimaries,
            missingAncestors: $missingAncestors,
            semanticConflicts: $semantic,
            provenanceIssues: $provenanceIssues,
            residualNone: $residualNone,
            reviewPriority: $reviewPriority,
            spotCheck: $this->spotCheck($products),
            inactiveHintRelationships: array_values(array_unique($inactiveHints, SORT_REGULAR)),
            taxonomyFromNonHintLists: array_values(array_unique($taxonomyFromNonHintLists, SORT_REGULAR)),
            reviewCount: $classificationCounts[TaxonomyClassificationStatus::Review->value] ?? 0,
            failedCount: $classificationCounts[TaxonomyClassificationStatus::Failed->value] ?? 0,
            aiAcceptedCount: $classificationCounts[TaxonomyClassificationStatus::AiAccepted->value] ?? 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productRef(Product $product): array
    {
        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();

        return [
            'product_id' => $product->id,
            'external_id' => $link?->external_product_id,
            'title' => $this->contentFingerprint->sourceTitle($product),
            'status' => $product->taxonomy_classification_status?->value
                ?? TaxonomyClassificationStatus::None->value,
        ];
    }

    private function taxonomyFromProduct(Product $product): ValidatedProductTaxonomyClassification
    {
        $primary = $product->categories->first(
            fn ($category): bool => (bool) $category->pivot?->is_primary,
        );

        return new ValidatedProductTaxonomyClassification(
            primaryCategoryId: $primary?->id,
            categoryIds: $product->categories->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            occasionIds: $product->occasions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            relationshipIds: $product->relationships->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            recipientTypeIds: $product->recipientTypes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            interestIds: $product->interests->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            professionIds: $product->professions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            giftTypeIds: $product->giftTypes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            exceptionCodes: [],
            rejectedIds: [],
        );
    }

    private function reviewPriority(Product $product): int
    {
        $reasons = is_array($product->taxonomy_review_reasons) ? $product->taxonomy_review_reasons : [];

        if (in_array(TaxonomyClassificationWarningCode::TrustedSourceSemanticConflict->value, $reasons, true)) {
            return 2;
        }

        if (in_array(TaxonomyClassificationWarningCode::TaxonomyGap->value, $reasons, true)
            || in_array(TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value, $reasons, true)) {
            return 3;
        }

        if (in_array(TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value, $reasons, true)) {
            return 4;
        }

        if (in_array(TaxonomyClassificationWarningCode::LowGiftTypeConfidence->value, $reasons, true)) {
            return 5;
        }

        return 6;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function spotCheck($products): array
    {
        $accepted = $products
            ->filter(fn (Product $product): bool => $product->taxonomy_classification_status === TaxonomyClassificationStatus::AiAccepted)
            ->values();

        if ($accepted->isEmpty()) {
            return [];
        }

        $picked = collect();

        $multiList = $accepted->filter(function (Product $product): bool {
            return $product->affiliateLinks
                ->flatMap(fn ($link) => $link->catalogProductSources)
                ->count() > 1;
        });
        $picked = $picked->concat($multiList->take(3));

        $childCategory = $accepted->filter(function (Product $product): bool {
            return $product->categories->contains(
                fn ($category): bool => (bool) $category->pivot?->is_primary && $category->parent_id !== null,
            );
        });
        $picked = $picked->concat($childCategory->take(3));

        $specialTypes = $accepted->filter(function (Product $product): bool {
            return $product->giftTypes->contains(
                fn ($type): bool => in_array($type->slug, ['personalized-gifts', 'hampers', 'experience'], true),
            );
        });
        $picked = $picked->concat($specialTypes->take(3));

        $priced = $accepted->sortBy(fn (Product $product): float => (float) $product->price_amount);
        $picked = $picked->concat($priced->take(1))->concat($priced->reverse()->take(1));

        $remaining = $accepted->reject(
            fn (Product $product): bool => $picked->contains(fn (Product $row): bool => $row->id === $product->id),
        );
        $picked = $picked->concat($remaining->shuffle()->take(max(0, 20 - $picked->count())));

        return $picked
            ->unique('id')
            ->take(20)
            ->map(fn (Product $product): array => $this->productRef($product) + [
                'price_amount' => $product->price_amount,
                'multi_list' => $product->affiliateLinks->flatMap(fn ($link) => $link->catalogProductSources)->count() > 1,
            ])
            ->values()
            ->all();
    }
}
